<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use MatrixOne\MatrixOneServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use PDO;

abstract class TestCase extends OrchestraTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Test classes wipe and rebuild the schema in different ways, so each
        // class re-runs its one-time migration instead of trusting the
        // process-wide flag left behind by a previous class.
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$lazilyRefreshed = false;

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s', self::env('MATRIXONE_HOST', '127.0.0.1'), self::env('MATRIXONE_PORT', '6001')),
            self::env('MATRIXONE_USERNAME', 'root'),
            self::env('MATRIXONE_PASSWORD', '111'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $pdo->exec('create database if not exists `'.self::env('MATRIXONE_DATABASE', 'laravel_matrixone_test').'`');
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [MatrixOneServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'matrixone');

        $app['config']->set('database.connections.matrixone', [
            'driver' => 'matrixone',
            'host' => self::env('MATRIXONE_HOST', '127.0.0.1'),
            'port' => (int) self::env('MATRIXONE_PORT', '6001'),
            'database' => self::env('MATRIXONE_DATABASE', 'laravel_matrixone_test'),
            'username' => self::env('MATRIXONE_USERNAME', 'root'),
            'password' => self::env('MATRIXONE_PASSWORD', '111'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }
}
