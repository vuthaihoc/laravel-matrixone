<?php

namespace MatrixOne\Tests\Unit;

use LogicException;
use MatrixOne\MatrixOneConnection;
use MatrixOne\Query\Builder;
use MatrixOne\Schema\Blueprint;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A connection whose PDO must never be touched: unit tests only compile SQL.
     */
    /**
     * @param  array<string, mixed>  $config
     */
    protected function connection(array $config = []): MatrixOneConnection
    {
        return new MatrixOneConnection(function () {
            throw new LogicException('Unit tests must not open a database connection.');
        }, 'test', '', array_merge(['driver' => 'matrixone', 'name' => 'matrixone'], $config));
    }

    protected function query(): Builder
    {
        return $this->connection()->query();
    }

    /**
     * Compile a blueprint to SQL statements.
     *
     * @param  callable(Blueprint): void  $callback
     * @return string[]
     */
    protected function blueprintSql(string $table, callable $callback, bool $create = false, ?MatrixOneConnection $connection = null): array
    {
        $connection ??= $this->connection();
        $connection->useDefaultSchemaGrammar();

        $blueprint = new Blueprint($connection, $table);

        if ($create) {
            $blueprint->create();
        }

        $callback($blueprint);

        // Pretending makes catalog lookups (e.g. existing column types)
        // return no rows instead of touching the database.
        $sql = [];
        $connection->pretend(function () use ($blueprint, &$sql) {
            $sql = $blueprint->toSql();
        });

        return $sql;
    }
}
