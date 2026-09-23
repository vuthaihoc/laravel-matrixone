<?php

namespace MatrixOne;

use Closure;
use Exception;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use MatrixOne\Connectors\MatrixOneConnector;
use MatrixOne\Query\Builder as QueryBuilder;
use MatrixOne\Query\Grammar as QueryGrammar;
use MatrixOne\Query\Processors\MatrixOneProcessor;
use MatrixOne\Schema\Builder as SchemaBuilder;
use MatrixOne\Schema\Grammar as SchemaGrammar;
use RuntimeException;

class MatrixOneConnection extends MySqlConnection
{
    /** {@inheritDoc} */
    public function getDriverTitle()
    {
        return 'MatrixOne';
    }

    /** {@inheritDoc} */
    public function isMaria()
    {
        return false;
    }

    /**
     * Get the MatrixOne release version, e.g. "3.0.9" from the server
     * version string "8.0.30-MatrixOne-v3.0.9".
     */
    public function getMatrixOneVersion(): ?string
    {
        if (preg_match('/MatrixOne-v?([0-9][^\s-]*)/i', $this->getServerVersion(), $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Set MatrixOne session variables on the open connection(s).
     *
     * The values last until the connection is closed or reconnected; use the
     * `variables` connection option for values that must survive reconnects.
     *
     * @param  array<string, mixed>  $variables
     */
    public function setSessionVariables(array $variables): void
    {
        if ($variables === []) {
            return;
        }

        $pdo = $this->getPdo();
        $sql = MatrixOneConnector::compileSetSessionVariables($pdo, $variables);

        $this->statement($sql);

        // A separate read connection has its own session.
        if (! is_null($this->readPdo) && ! $this->pretending()) {
            $readPdo = $this->getReadPdo();

            if ($readPdo !== $pdo) {
                $readPdo->exec($sql);
            }
        }
    }

    /**
     * Run the callback with the given session variables, then restore their
     * previous values, e.g.
     * `DB::connection('matrixone')->withSessionVariables(['ft_relevancy_algorithm' => 'BM25'], fn () => ...)`.
     *
     * @template TReturn
     *
     * @param  array<string, mixed>  $variables
     * @param  Closure(static): TReturn  $callback
     * @return TReturn
     */
    public function withSessionVariables(array $variables, Closure $callback): mixed
    {
        $previous = $this->getSessionVariables(array_keys($variables));

        $this->setSessionVariables($variables);

        try {
            return $callback($this);
        } finally {
            $this->setSessionVariables($previous);
        }
    }

    /**
     * Read the current values of the given session variables.
     *
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    public function getSessionVariables(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $columns = array_map(function ($name) {
            if (! preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
                throw new InvalidArgumentException("Invalid MatrixOne session variable name [{$name}].");
            }

            return "@@session.{$name} as `{$name}`";
        }, $names);

        /** @var array<string, mixed> $values */
        $values = (array) $this->selectOne('select '.implode(', ', $columns), [], false);

        return $values;
    }

    /**
     * Whether query rewrites for MatrixOne bugs are active, see withCompatibilityRewrites().
     */
    protected bool $compatibilityRewrites = false;

    /**
     * Run the callback with rewrites that work around MatrixOne bugs in
     * query shapes some packages rely on (used for Laravel Pulse):
     *
     * - `selectRaw('null as alias')` becomes `cast(null as double) as alias`:
     *   a bare NULL in a UNION is typed VARCHAR, breaking sum()/avg() over it.
     * - A select-list subquery selecting one column with `limit 1` selects
     *   `max(column)` without the limit: MatrixOne returns NULL for most rows
     *   of a correlated scalar subquery with LIMIT. Only use this where every
     *   candidate row holds the same value (e.g. looking up a name by hash).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withCompatibilityRewrites(Closure $callback): mixed
    {
        $previous = $this->compatibilityRewrites;
        $this->compatibilityRewrites = true;

        try {
            return $callback();
        } finally {
            $this->compatibilityRewrites = $previous;
        }
    }

    /**
     * Determine whether compatibility rewrites are active.
     */
    public function usesCompatibilityRewrites(): bool
    {
        return $this->compatibilityRewrites;
    }

    /** {@inheritDoc} */
    public function query()
    {
        return new QueryBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    /**
     * Error messages PDO reports when MatrixOne aborted a statement without
     * a protocol-level error (a server-side panic). The connection is left
     * mid-response and cannot run another query.
     *
     * @var string[]
     */
    protected array $brokenConnectionMessages = [
        "Error reading result set's header",
        "Packet buffer wasn't big enough",
        'Cannot execute queries while other unbuffered queries are active',
    ];

    /**
     * {@inheritDoc}
     *
     * After a MatrixOne panic the PDO connection is unusable, yet the server
     * keeps its session, transaction and row locks until the socket closes;
     * the next write on the same rows then blocks. The broken connection is
     * dropped (the next query reconnects) and the exception is rethrown:
     * retrying would crash the server again.
     *
     * @param  array<int|string, mixed>  $bindings
     */
    protected function handleQueryException(QueryException $e, $query, $bindings, Closure $callback)
    {
        if ($this->causedByBrokenConnection($e)) {
            $this->discardBrokenConnection();

            throw $e;
        }

        return parent::handleQueryException($e, $query, $bindings, $callback);
    }

    /**
     * Determine if the exception left the connection unusable.
     */
    protected function causedByBrokenConnection(QueryException $e): bool
    {
        $message = ($e->getPrevious() ?? $e)->getMessage();

        foreach ($this->brokenConnectionMessages as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop a broken connection and forget its open transactions: the server
     * rolls them back when the session ends.
     */
    protected function discardBrokenConnection(): void
    {
        $this->disconnect();

        if ($this->transactions > 0) {
            $this->transactionsManager?->rollback($this->getName() ?? 'matrixone', 0);
            $this->transactions = 0;
        }
    }

    /** {@inheritDoc} */
    protected function isUniqueConstraintError(Exception $exception)
    {
        return (bool) preg_match('#(Integrity constraint violation|General error): 1062#i', $exception->getMessage());
    }

    /** {@inheritDoc} */
    protected function getDefaultQueryGrammar()
    {
        return new QueryGrammar($this);
    }

    /** {@inheritDoc} */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SchemaBuilder($this);
    }

    /** {@inheritDoc} */
    protected function getDefaultSchemaGrammar()
    {
        return new SchemaGrammar($this);
    }

    /** {@inheritDoc} */
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null)
    {
        throw new RuntimeException('Schema dumping is not supported when using MatrixOne.');
    }

    /** {@inheritDoc} */
    protected function getDefaultPostProcessor()
    {
        return new MatrixOneProcessor;
    }
}
