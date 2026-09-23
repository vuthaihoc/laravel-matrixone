<?php

namespace MatrixOne\Tests\Feature;

use Carbon\CarbonInterval;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\PulseServiceProvider;
use Laravel\Pulse\Storage\DatabaseStorage;
use MatrixOne\MatrixOneServiceProvider;
use MatrixOne\Pulse\MatrixOneStorage;

/**
 * Laravel Pulse storing its data in MatrixOne, with the package's Pulse
 * migration and storage.
 */
class PulseTest extends TestCase
{
    /** @var Migration */
    private $migration;

    protected function getPackageProviders($app): array
    {
        return [MatrixOneServiceProvider::class, PulseServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('pulse.enabled', true);
        $app['config']->set('pulse.storage.driver', 'database');
        $app['config']->set('pulse.storage.database.connection', null);
        $app['config']->set('pulse.ingest.driver', 'storage');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require __DIR__.'/../../database/pulse/2023_06_07_000001_create_pulse_tables.php';
        $this->migration->down();
        $this->migration->up();

        Carbon::setTestNow('2026-09-23 10:00:30');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->migration->down();

        parent::tearDown();
    }

    private function storage(): DatabaseStorage
    {
        return $this->app->make(DatabaseStorage::class);
    }

    public function testTheMatrixOneStorageIsBound(): void
    {
        $this->assertInstanceOf(MatrixOneStorage::class, $this->storage());
    }

    public function testRecordingAndReadingAggregates(): void
    {
        // Two ingests make the second one hit ON DUPLICATE KEY UPDATE.
        foreach ([[100, 300], [200]] as $batch) {
            foreach ($batch as $duration) {
                Pulse::record('slow_request', 'GET /users', $duration)->count()->sum()->max()->min()->avg();
            }
            Pulse::record('slow_request', 'GET /posts', 50)->count()->max();
            Pulse::ingest();
        }

        $this->assertSame(5, DB::table('pulse_entries')->count());
        $this->assertSame(32, strlen((string) DB::table('pulse_entries')->value('key_hash')));

        $rows = $this->storage()->aggregate('slow_request', ['count', 'sum', 'max', 'min', 'avg'], CarbonInterval::hour());
        $users = $rows->firstWhere('key', 'GET /users');

        $this->assertEquals(3, $users->count);
        $this->assertEquals(600, $users->sum);
        $this->assertEquals(300, $users->max);
        $this->assertEquals(100, $users->min);
        $this->assertEquals(200, $users->avg);
        $this->assertSame(['GET /users', 'GET /posts'], $rows->pluck('key')->all());

        $this->assertEquals(5, $this->storage()->aggregateTotal('slow_request', 'count', CarbonInterval::hour()));
        $this->assertEquals(300, $this->storage()->aggregateTotal('slow_request', 'max', CarbonInterval::hour()));

        $types = $this->storage()->aggregateTypes(['slow_request'], 'count', CarbonInterval::hour());
        $this->assertEquals(3, $types->firstWhere('key', 'GET /users')->slow_request);

        $graph = $this->storage()->graph(['slow_request'], 'count', CarbonInterval::hour());
        $this->assertEquals(3, $graph['GET /users']['slow_request']->sum());
    }

    public function testValues(): void
    {
        Pulse::set('system', 'web-1', json_encode(['cpu' => 10]));
        Pulse::ingest();
        Pulse::set('system', 'web-1', json_encode(['cpu' => 55]));
        Pulse::set('system', 'web-2', json_encode(['cpu' => 20]));
        Pulse::ingest();

        $values = $this->storage()->values('system');

        $this->assertCount(2, $values);
        $this->assertSame(55, json_decode($values['web-1']->value, true)['cpu']);
        $this->assertCount(1, $this->storage()->values('system', ['web-2']));
    }

    public function testTrimAndPurge(): void
    {
        Pulse::record('old', 'k', 1)->count();
        Pulse::ingest();

        Carbon::setTestNow(now()->addDays(8));
        Pulse::record('new', 'k', 1)->count();
        Pulse::ingest();

        $this->storage()->trim();
        $this->assertSame(['new'], DB::table('pulse_entries')->pluck('type')->all());

        $this->storage()->purge(['new']);
        $this->assertSame(0, DB::table('pulse_entries')->count());

        $this->storage()->purge();
        $this->assertSame(0, DB::table('pulse_aggregates')->count());
    }

    public function testThePublishedMigrationIsRegistered(): void
    {
        $this->assertArrayHasKey(
            realpath(__DIR__.'/../../database/pulse') ?: '',
            array_flip(array_map(fn ($path) => realpath($path) ?: $path, array_keys(ServiceProvider::pathsToPublish(MatrixOneServiceProvider::class, 'matrixone-pulse-migrations'))))
        );
        $this->assertTrue(Schema::hasTable('pulse_aggregates'));
    }
}
