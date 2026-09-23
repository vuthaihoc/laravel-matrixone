<?php

namespace MatrixOne;

use Closure;
use Exception;
use Illuminate\Database\MySqlConnection;
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

    /** {@inheritDoc} */
    public function query()
    {
        return new QueryBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
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
