<?php

namespace MatrixOne;

use Closure;
use Exception;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use MatrixOne\Connectors\MatrixOneConnector;
use MatrixOne\Monitoring\ExecutionPlan;
use MatrixOne\Monitoring\StatementLogQuery;
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

    /**
     * Take a snapshot of the connection's database, or of one of its tables.
     * Read it back with asOfSnapshot() on a query.
     */
    public function createSnapshot(string $name, ?string $table = null): void
    {
        $this->ensureValidSnapshotName($name);

        $this->statement('create snapshot '.$this->wrapIdentifier($name).' for '.$this->snapshotTarget($table));
    }

    /**
     * Take a snapshot of the whole account (every database of the tenant).
     */
    public function createAccountSnapshot(string $name): void
    {
        $this->ensureValidSnapshotName($name);

        $this->statement('create snapshot '.$this->wrapIdentifier($name).' for account');
    }

    public function dropSnapshot(string $name): void
    {
        $this->ensureValidSnapshotName($name);

        $this->statement('drop snapshot if exists '.$this->wrapIdentifier($name));
    }

    /**
     * The snapshots visible to the account, with lower-case keys
     * (snapshot_name, timestamp, snapshot_level, account_name, database_name, table_name).
     *
     * @return list<array<string, mixed>>
     */
    public function getSnapshots(): array
    {
        return $this->lowerCaseKeys($this->select('show snapshots'));
    }

    public function hasSnapshot(string $name): bool
    {
        foreach ($this->getSnapshots() as $snapshot) {
            if ($snapshot['snapshot_name'] === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep point-in-time recovery data of the connection's database (or of
     * one table) for $length units: h (hours), d (days), mo (months), y (years).
     * Read past states with asOfTimestamp() on a query.
     */
    public function createPitr(string $name, int $length, string $unit = 'd', ?string $table = null): void
    {
        $this->ensureValidSnapshotName($name);

        $this->statement('create pitr '.$this->wrapIdentifier($name).' for '.$this->snapshotTarget($table).' range '.$this->pitrRange($length, $unit));
    }

    /**
     * Change the retention of a PITR.
     */
    public function alterPitr(string $name, int $length, string $unit = 'd'): void
    {
        $this->ensureValidSnapshotName($name);

        $this->statement('alter pitr '.$this->wrapIdentifier($name).' range '.$this->pitrRange($length, $unit));
    }

    public function dropPitr(string $name): void
    {
        $this->ensureValidSnapshotName($name);

        $this->statement('drop pitr if exists '.$this->wrapIdentifier($name));
    }

    /**
     * The PITRs visible to the account, with lower-case keys
     * (pitr_name, created_time, modified_time, pitr_level, account_name,
     * database_name, table_name, pitr_length, pitr_unit).
     *
     * @return list<array<string, mixed>>
     */
    public function getPitrs(): array
    {
        return $this->lowerCaseKeys($this->select('show pitr'));
    }

    /**
     * Query MatrixOne's statement history (system.statement_info): by default
     * the application's statements on this database in the last hour.
     *
     *     $db->statementLog()->slowerThan(500)->slowest()->summary()->limit(20)->get();
     *     $db->statementLog()->since('1d')->failed()->latest('request_at')->get();
     *
     * Statements appear a few seconds after they finish. Short, repeated
     * statements are merged into one row ("/* N queries *\/", aggr_count).
     */
    public function statementLog(): StatementLogQuery
    {
        $query = new StatementLogQuery($this, $this->getQueryGrammar(), $this->getPostProcessor());

        return $query->from(new Expression('`system`.`statement_info`'))->withDefaults($this->getDatabaseName());
    }

    /**
     * The execution plan MatrixOne recorded for a statement, or null when it
     * kept none: plans are only recorded for statements running for at least
     * `longQueryTime` (1 second by default).
     */
    public function getStatementPlan(string $statementId): ?ExecutionPlan
    {
        $json = $this->statementLog()->includeInternal()->allDatabases()->since('30d')
            ->where('statement_id', $statementId)->value('exec_plan');

        return ExecutionPlan::fromJson(is_string($json) ? $json : null);
    }

    /**
     * Statistics of a table: MatrixOne's row count and storage size (both
     * refreshed asynchronously, up to about a minute late), its number of
     * columns and, with $values, the minimum and maximum of every column.
     *
     * @return array{rows: int, size: int, columns: int, values: array<string, array{min: mixed, max: mixed}>}
     */
    public function tableStats(string $table, bool $values = true): array
    {
        $database = $this->getDatabaseName();
        $table = $this->getTablePrefix().$table;
        $qualified = $this->wrapIdentifier($database).'.'.$this->wrapIdentifier($table);

        $counts = (array) $this->selectOne('select mo_table_rows(?, ?) as `rows`, mo_table_size(?, ?) as `size`', [$database, $table, $database, $table]);
        $columns = array_values((array) $this->selectOne('show column_number from '.$qualified));

        $stats = [
            'rows' => is_numeric($counts['rows'] ?? null) ? (int) $counts['rows'] : 0,
            'size' => is_numeric($counts['size'] ?? null) ? (int) $counts['size'] : 0,
            'columns' => is_numeric($columns[0] ?? null) ? (int) $columns[0] : 0,
            'values' => [],
        ];

        if ($values) {
            foreach ((array) $this->selectOne('show table_values from '.$qualified) as $key => $value) {
                if (preg_match('/^(max|min)\((.+)\)$/i', (string) $key, $matches)) {
                    $stats['values'][$matches[2]][strtolower($matches[1])] = $value;
                }
            }

            $stats['values'] = array_map(fn (array $range) => ['min' => $range['min'] ?? null, 'max' => $range['max'] ?? null], $stats['values']);
        }

        return $stats;
    }

    /**
     * "database `db`" or "table `db` `prefixed_table`".
     */
    protected function snapshotTarget(?string $table): string
    {
        $database = $this->wrapIdentifier($this->getDatabaseName());

        return $table === null
            ? 'database '.$database
            : 'table '.$database.' '.$this->wrapIdentifier($this->getTablePrefix().$table);
    }

    /**
     * MatrixOne accepts letters, digits, "_" and "-" in snapshot and PITR names.
     */
    protected function ensureValidSnapshotName(string $name): void
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
            throw new InvalidArgumentException("Invalid snapshot or PITR name [{$name}]: use letters, digits, \"_\" and \"-\".");
        }
    }

    protected function pitrRange(int $length, string $unit): string
    {
        if ($length < 1 || ! in_array($unit, ['h', 'd', 'mo', 'y'], true)) {
            throw new InvalidArgumentException('A PITR range is a positive length in h, d, mo or y.');
        }

        return $length.' '.$this->getQueryGrammar()->quoteString($unit);
    }

    protected function wrapIdentifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array<string, mixed>>
     */
    protected function lowerCaseKeys(array $rows): array
    {
        return array_values(array_map(fn ($row) => array_change_key_case((array) $row), $rows));
    }

    /** {@inheritDoc} */
    protected function getDefaultPostProcessor()
    {
        return new MatrixOneProcessor;
    }
}
