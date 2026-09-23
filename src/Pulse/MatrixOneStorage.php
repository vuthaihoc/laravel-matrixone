<?php

namespace MatrixOne\Pulse;

use Carbon\CarbonInterval;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use Laravel\Pulse\Storage\DatabaseStorage;
use MatrixOne\MatrixOneConnection;

/**
 * Pulse's database storage for MatrixOne connections.
 *
 * Pulse only knows the mysql, mariadb, pgsql and sqlite drivers. On MatrixOne
 * this storage uses MySQL's upsert expressions (`values(column)`) and hashes
 * keys in PHP, like Pulse does for SQLite, because the MySQL schema relies
 * on a generated `key_hash` column that MatrixOne does not support. Other
 * connections keep Pulse's behaviour.
 */
class MatrixOneStorage extends DatabaseStorage
{
    protected function onMatrixOne(): bool
    {
        return $this->connection() instanceof MatrixOneConnection;
    }

    /**
     * Pulse's read queries UNION rows with `null as ...` placeholders (typed
     * VARCHAR by MatrixOne) and look keys up with a correlated `limit 1`
     * subquery (NULL on MatrixOne); they run with compatibility rewrites.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function withTypedNulls(\Closure $callback): mixed
    {
        $connection = $this->connection();

        return $connection instanceof MatrixOneConnection
            ? $connection->withCompatibilityRewrites($callback)
            : $callback();
    }

    public function aggregate(string $type, array|string $aggregates, CarbonInterval $interval, ?string $orderBy = null, string $direction = 'desc', int $limit = 101): Collection
    {
        return $this->withTypedNulls(fn () => parent::aggregate($type, $aggregates, $interval, $orderBy, $direction, $limit));
    }

    public function aggregateTypes(string|array $types, string $aggregate, CarbonInterval $interval, ?string $orderBy = null, string $direction = 'desc', int $limit = 101): Collection
    {
        return $this->withTypedNulls(fn () => parent::aggregateTypes($types, $aggregate, $interval, $orderBy, $direction, $limit));
    }

    public function aggregateTotal(array|string $types, string $aggregate, CarbonInterval $interval): float|Collection
    {
        return $this->withTypedNulls(fn () => parent::aggregateTotal($types, $aggregate, $interval));
    }

    public function graph(array $types, string $aggregate, CarbonInterval $interval): Collection
    {
        return $this->withTypedNulls(fn () => parent::graph($types, $aggregate, $interval));
    }

    protected function requiresManualKeyHash(): bool
    {
        return $this->onMatrixOne() || parent::requiresManualKeyHash();
    }

    /**
     * @param  list<array<string, mixed>>  $values
     */
    protected function upsertCount(array $values): int
    {
        return $this->onMatrixOne()
            ? $this->upsertAggregate($values, ['value' => '`value` + values(`value`)'])
            : parent::upsertCount($values); // @phpstan-ignore argument.type
    }

    /**
     * @param  list<array<string, mixed>>  $values
     */
    protected function upsertMin(array $values): int
    {
        return $this->onMatrixOne()
            ? $this->upsertAggregate($values, ['value' => 'least(`value`, values(`value`))'])
            : parent::upsertMin($values); // @phpstan-ignore argument.type
    }

    /**
     * @param  list<array<string, mixed>>  $values
     */
    protected function upsertMax(array $values): int
    {
        return $this->onMatrixOne()
            ? $this->upsertAggregate($values, ['value' => 'greatest(`value`, values(`value`))'])
            : parent::upsertMax($values); // @phpstan-ignore argument.type
    }

    /**
     * @param  list<array<string, mixed>>  $values
     */
    protected function upsertSum(array $values): int
    {
        return $this->onMatrixOne()
            ? $this->upsertAggregate($values, ['value' => '`value` + values(`value`)'])
            : parent::upsertSum($values); // @phpstan-ignore argument.type
    }

    /**
     * MatrixOne evaluates both assignments against the original row, which is
     * exactly what this weighted average needs.
     *
     * @param  list<array<string, mixed>>  $values
     */
    protected function upsertAvg(array $values): int
    {
        return $this->onMatrixOne()
            ? $this->upsertAggregate($values, [
                'value' => '(`value` * `count` + (values(`value`) * values(`count`))) / (`count` + values(`count`))',
                'count' => '`count` + values(`count`)',
            ])
            : parent::upsertAvg($values); // @phpstan-ignore argument.type
    }

    /**
     * @param  list<array<string, mixed>>  $values
     * @param  array<string, string>  $updates
     */
    protected function upsertAggregate(array $values, array $updates): int
    {
        return $this->connection()->table('pulse_aggregates')->upsert(
            $values,
            ['bucket', 'period', 'type', 'aggregate', 'key_hash'],
            array_map(fn (string $sql) => new Expression($sql), $updates),
        );
    }
}
