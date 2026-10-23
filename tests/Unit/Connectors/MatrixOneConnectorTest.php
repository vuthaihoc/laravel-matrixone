<?php

namespace MatrixOne\Tests\Unit\Connectors;

use InvalidArgumentException;
use MatrixOne\Connectors\MatrixOneConnector;
use MatrixOne\Tests\Unit\TestCase;
use PDO;

class MatrixOneConnectorTest extends TestCase
{
    public function testTheDatabaseIsNotSelectedWithUseAfterConnecting(): void
    {
        $this->assertSame([], $this->statementsRunOnConnect(['database' => 'app']));
    }

    public function testUseAfterConnectingCanBeEnabled(): void
    {
        $this->assertSame(['use `app`;'], $this->statementsRunOnConnect(['database' => 'app', 'use_db_after_connecting' => true]));
    }

    /**
     * Connect with a fake PDO and return the statements it executed.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function statementsRunOnConnect(array $config): array
    {
        $statements = [];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('exec')->willReturnCallback(function (string $statement) use (&$statements) {
            $statements[] = $statement;

            return 0;
        });

        $connector = new class($pdo) extends MatrixOneConnector
        {
            public function __construct(private PDO $pdo) {}

            public function createConnection($dsn, array $config, array $options)
            {
                return $this->pdo;
            }
        };

        $connector->connect(array_merge(['host' => '127.0.0.1', 'port' => 6001], $config));

        return $statements;
    }

    public function testCompileSetSessionVariables(): void
    {
        // Any PDO can quote; SQLite keeps the test offline.
        $pdo = new PDO('sqlite::memory:');

        $this->assertSame(
            "set session ft_relevancy_algorithm = 'BM25', session experimental_hnsw_index = 1, session fulltext_bloom_filter_pushdown = 0, session ratio = 0.5",
            MatrixOneConnector::compileSetSessionVariables($pdo, [
                'ft_relevancy_algorithm' => 'BM25',
                'experimental_hnsw_index' => true,
                'fulltext_bloom_filter_pushdown' => 0,
                'ratio' => 0.5,
            ])
        );
        $this->assertSame("set session x = 'it''s'", MatrixOneConnector::compileSetSessionVariables($pdo, ['x' => "it's"]));
    }

    public function testInvalidVariableNamesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MatrixOneConnector::compileSetSessionVariables(new PDO('sqlite::memory:'), ['x = 1; drop table t' => 1]);
    }

    public function testInvalidVariableValuesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MatrixOneConnector::compileSetSessionVariables(new PDO('sqlite::memory:'), ['x' => ['array']]);
    }
}
