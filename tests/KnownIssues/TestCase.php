<?php

namespace MatrixOne\Tests\KnownIssues;

use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Throwable;

/**
 * Known MatrixOne bugs and MySQL incompatibilities, written as plain SQL that
 * MySQL 8 accepts. Each test asserts the MySQL behaviour, so it FAILS while
 * the issue exists and starts PASSING once a MatrixOne release fixes it; the
 * matching driver workaround (named in each test) can then be reconsidered.
 *
 * Not part of `composer test`; run with `composer test:known-issues`.
 */
abstract class TestCase extends BaseTestCase
{
    protected const DATABASE = 'laravel_matrixone_known_issues';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $pdo = self::connect(null);
        $pdo->exec('drop database if exists `'.self::DATABASE.'`');
        $pdo->exec('create database `'.self::DATABASE.'`');
    }

    public static function tearDownAfterClass(): void
    {
        self::connect(null)->exec('drop database if exists `'.self::DATABASE.'`');

        parent::tearDownAfterClass();
    }

    /**
     * Open a new connection. A MatrixOne panic can leave a connection unusable,
     * so every check that may crash the server gets its own.
     */
    protected static function connect(?string $database = self::DATABASE): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%s', self::env('MATRIXONE_HOST', '127.0.0.1'), self::env('MATRIXONE_PORT', '6001'));

        if ($database !== null) {
            $dsn .= ';dbname='.$database;
        }

        return new PDO($dsn, self::env('MATRIXONE_USERNAME', 'root'), self::env('MATRIXONE_PASSWORD', '111'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_TIMEOUT => 10,
        ]);
    }

    /**
     * Run statements on a fresh connection and return the rows of the last
     * one, or fail with the server error.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function runSql(string ...$statements): array
    {
        $pdo = self::connect();
        $rows = [];

        foreach ($statements as $statement) {
            try {
                // Suppress the PDO warning emitted before the exception on
                // a broken connection.
                $result = @$pdo->query($statement);
                $rows = $result !== false && $result->columnCount() > 0 ? $result->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Throwable $e) {
                $this->fail("MatrixOne rejected the statement.\nSQL: {$statement}\nError: {$e->getMessage()}");
            }
        }

        return $rows;
    }

    /**
     * Run one statement on a fresh connection and return its rows, or fail
     * when MatrixOne rejects it or does not answer within $seconds. A statement
     * that timed out is killed so it does not keep running on the server.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function runSqlWithTimeout(int $seconds, string $statement): array
    {
        // mysqlnd reads the timeout when the connection is opened.
        $previous = (string) ini_get('mysqlnd.net_read_timeout');
        ini_set('mysqlnd.net_read_timeout', (string) $seconds);

        try {
            $pdo = self::connect();
        } finally {
            ini_set('mysqlnd.net_read_timeout', $previous);
        }

        $connectionId = (int) $pdo->query('select connection_id()')->fetchColumn();
        $started = microtime(true);

        try {
            return @$pdo->query($statement)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            if (microtime(true) - $started < $seconds) {
                $this->fail("MatrixOne rejected the statement.\nSQL: {$statement}\nError: {$e->getMessage()}");
            }
        }

        self::connect()->exec("kill query {$connectionId}");

        $this->fail("MatrixOne did not finish the statement within {$seconds} s (killed).\nSQL: {$statement}");
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }
}
