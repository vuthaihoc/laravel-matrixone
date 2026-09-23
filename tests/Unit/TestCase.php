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
    protected function connection(): MatrixOneConnection
    {
        return new MatrixOneConnection(function () {
            throw new LogicException('Unit tests must not open a database connection.');
        }, 'test', '', ['driver' => 'matrixone', 'name' => 'matrixone']);
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

        return $blueprint->toSql();
    }
}
