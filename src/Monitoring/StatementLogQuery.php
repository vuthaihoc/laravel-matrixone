<?php

namespace MatrixOne\Monitoring;

use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use MatrixOne\Query\Builder;

/**
 * A query on MatrixOne's statement history (system.statement_info), which
 * records every statement: text, duration, rows read, errors and plans.
 *
 * Created by MatrixOneConnection::statementLog() with three default filters
 * (application statements, the connection's database, the last hour), each
 * replaceable. Times in the table are UTC.
 */
class StatementLogQuery extends Builder
{
    /**
     * Apply the default filters.
     *
     * @return $this
     */
    public function withDefaults(string $database): static
    {
        return $this->forDatabase($database)->onlyApplication()->since('1h');
    }

    /**
     * Statements started after the given time, or within a duration such as
     * "15m", "1h", "2d" (s, m, h, d).
     *
     * @return $this
     */
    public function since(DateTimeInterface|string $time): static
    {
        if ($time instanceof DateTimeInterface) {
            $utc = Carbon::instance($time)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

            return $this->replaceDefault('since', '`request_at` >= '.$this->grammar->quoteLiteral($utc));
        }

        return $this->replaceDefault('since', '`request_at` >= utc_timestamp() - interval '.self::parseDuration($time).' second');
    }

    /**
     * Statements of every database.
     *
     * @return $this
     */
    public function allDatabases(): static
    {
        return $this->replaceDefault('database', null);
    }

    /**
     * Statements run against the given database.
     *
     * @return $this
     */
    public function forDatabase(string $database): static
    {
        return $this->replaceDefault('database', '`database` = '.$this->grammar->quoteLiteral($database));
    }

    /**
     * Only statements sent by clients (not MatrixOne's internal SQL).
     *
     * @return $this
     */
    public function onlyApplication(): static
    {
        return $this->replaceDefault('source', "`sql_source_type` = 'external_sql'");
    }

    /**
     * Include MatrixOne's internal SQL (background tasks, metrics).
     *
     * @return $this
     */
    public function includeInternal(): static
    {
        return $this->replaceDefault('source', null);
    }

    /**
     * Statements that took at least $milliseconds (of any type).
     *
     * @return $this
     */
    public function slowerThan(float $milliseconds): static
    {
        return $this->where('duration', '>=', (int) round($milliseconds * 1_000_000));
    }

    /**
     * Statements that failed.
     *
     * @return $this
     */
    public function failed(): static
    {
        return $this->where('status', 'Failed');
    }

    /**
     * Statements of the given types: Select, Insert, Update, Delete, Create Table...
     *
     * @return $this
     */
    public function ofType(string ...$types): static
    {
        return $this->whereIn('statement_type', $types);
    }

    /**
     * Slowest first.
     *
     * @return $this
     */
    public function slowest(): static
    {
        return $this->orderByDesc('duration');
    }

    /**
     * Select the useful columns, with the duration in milliseconds.
     *
     * @return $this
     */
    public function summary(): static
    {
        return $this->select([
            'request_at', 'statement_id', 'statement_type', 'status', 'database', 'user',
            'rows_read', 'bytes_scan', 'result_count', 'aggr_count', 'err_code', 'error', 'statement',
        ])->selectRaw('round(`duration` / 1000000, 3) as `duration_ms`');
    }

    /**
     * Parse "<n>s|m|h|d" into seconds.
     */
    public static function parseDuration(string $duration): int
    {
        if (! preg_match('/^\s*(\d+)\s*(s|m|h|d)\s*$/i', $duration, $matches)) {
            throw new InvalidArgumentException("Invalid duration [{$duration}]: use a number followed by s, m, h or d (e.g. 15m).");
        }

        return (int) $matches[1] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][strtolower($matches[2])];
    }

    /**
     * Replace (or remove, with null) one of the default filters. They are raw
     * conditions without bindings, so removing one keeps the bindings aligned.
     *
     * @return $this
     */
    protected function replaceDefault(string $name, ?string $sql): static
    {
        $this->wheres = array_values(array_filter(
            $this->wheres,
            fn (array $where) => ($where['statementLog'] ?? null) !== $name,
        ));

        if ($sql !== null) {
            $this->wheres[] = ['type' => 'raw', 'sql' => $sql, 'boolean' => 'and', 'statementLog' => $name];
        }

        return $this;
    }
}
