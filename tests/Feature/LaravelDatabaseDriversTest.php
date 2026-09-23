<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use MatrixOne\Tests\Feature\Jobs\RecordJob;

/**
 * MatrixOne as the backend of Laravel's database cache, locks, rate limiter,
 * queue (jobs, batches, failed jobs) and session drivers, using the tables of
 * the Laravel application skeleton.
 */
class LaravelDatabaseDriversTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'database');
        $app['config']->set('cache.stores.database', [
            'driver' => 'database',
            'connection' => null,
            'table' => 'cache',
            'lock_connection' => null,
            'lock_table' => 'cache_locks',
        ]);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => null,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        $app['config']->set('queue.batching', ['database' => 'matrixone', 'table' => 'job_batches']);
        $app['config']->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'matrixone', 'table' => 'failed_jobs']);
        $app['config']->set('session.driver', 'database');
        $app['config']->set('session.table', 'sessions');
        $app['config']->set('session.connection', null);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    private function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    }

    public function testCacheStore(): void
    {
        $cache = Cache::store('database');

        $this->assertTrue($cache->put('a', ['x' => 1], 60));
        $this->assertSame(['x' => 1], $cache->get('a'));
        $this->assertTrue($cache->has('a'));
        $this->assertNull($cache->get('missing'));

        // put() on an existing key goes through upsert().
        $cache->put('a', 'updated', 60);
        $this->assertSame('updated', $cache->get('a'));

        $this->assertTrue($cache->add('b', 1, 60));
        $this->assertFalse($cache->add('b', 2, 60));
        $this->assertSame(1, $cache->get('b'));

        $this->assertSame(6, $cache->increment('b', 5));
        $this->assertSame(4, $cache->decrement('b', 2));

        $cache->putMany(['m1' => 'one', 'm2' => 'two'], 60);
        $this->assertSame(['m1' => 'one', 'm2' => 'two'], $cache->many(['m1', 'm2']));

        $this->assertSame('computed', $cache->remember('r', 60, fn () => 'computed'));
        $this->assertSame('computed', $cache->remember('r', 60, fn () => 'not called'));
        $this->assertSame('computed', $cache->pull('r'));
        $this->assertNull($cache->get('r'));

        $cache->forever('f', 'forever');
        $this->assertTrue($cache->forget('f'));
        $this->assertNull($cache->get('f'));

        $this->assertTrue($cache->flush());
        $this->assertSame(0, DB::table('cache')->count());
    }

    public function testCacheExpiration(): void
    {
        $cache = Cache::store('database');
        $cache->put('short', 'value', 10);

        Carbon::setTestNow(now()->addSeconds(11));

        try {
            $this->assertNull($cache->get('short'));
            // An expired key can be added again.
            $this->assertTrue($cache->add('short', 'again', 10));
            $this->assertSame('again', $cache->get('short'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testCacheLocks(): void
    {
        $lock = Cache::store('database')->lock('report', 10);

        $this->assertTrue($lock->get());
        $this->assertFalse(Cache::store('database')->lock('report', 10)->get());
        $this->assertSame($lock->owner(), Cache::store('database')->restoreLock('report', $lock->owner())->owner());
        $this->assertTrue($lock->release());

        $this->assertSame('done', Cache::store('database')->lock('report', 10)->get(fn () => 'done'));
        $this->assertSame(0, DB::table('cache_locks')->count());

        // An expired lock can be taken over.
        Cache::store('database')->lock('stale', 5)->get();
        Carbon::setTestNow(now()->addSeconds(6));

        try {
            $this->assertTrue(Cache::store('database')->lock('stale', 5)->get());
        } finally {
            Carbon::setTestNow();
        }

        Cache::store('database')->lock('forced', 10)->get();
        Cache::store('database')->lock('forced')->forceRelease();
        $this->assertTrue(Cache::store('database')->lock('forced', 10)->get());
    }

    public function testRateLimiter(): void
    {
        foreach (range(1, 3) as $attempt) {
            $this->assertTrue(RateLimiter::attempt('send', 3, fn () => true));
        }

        $this->assertFalse(RateLimiter::attempt('send', 3, fn () => true));
        $this->assertTrue(RateLimiter::tooManyAttempts('send', 3));
        $this->assertSame(0, RateLimiter::remaining('send', 3));

        RateLimiter::clear('send');
        $this->assertFalse(RateLimiter::tooManyAttempts('send', 3));
    }

    public function testQueueRunsJobs(): void
    {
        RecordJob::dispatch('a');
        RecordJob::dispatch('b')->onQueue('default');

        $this->assertSame(2, Queue::size());
        $this->assertSame(2, DB::table('jobs')->count());

        $this->work();

        $this->assertSame(1, Cache::store('database')->get('job:a'));
        $this->assertSame(1, Cache::store('database')->get('job:b'));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function testDelayedAndReleasedJobs(): void
    {
        RecordJob::dispatch('later')->delay(now()->addMinutes(5));
        RecordJob::dispatch('released', 'release-once');

        $this->work();

        // The released job ran a second time; the delayed one is still waiting.
        $this->assertSame(2, Cache::store('database')->get('job:released'));
        $this->assertNull(Cache::store('database')->get('job:later'));
        $this->assertSame(1, DB::table('jobs')->count());

        Carbon::setTestNow(now()->addMinutes(6));

        try {
            $this->work();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(1, Cache::store('database')->get('job:later'));
    }

    public function testFailedJobsAndRetry(): void
    {
        RecordJob::dispatch('broken', 'fail');

        $this->work();

        $this->assertSame(0, DB::table('jobs')->count());
        $failed = DB::table('failed_jobs')->first();
        $this->assertNotNull($failed);
        $this->assertTrue(Str::isUuid($failed->uuid));
        $this->assertStringContainsString('Job broken failed.', $failed->exception);

        $this->assertCount(1, $this->app['queue.failer']->all());

        $this->artisan('queue:retry', ['id' => ['all']])->assertSuccessful();
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->count());

        $this->artisan('queue:flush')->assertSuccessful();
    }

    public function testJobBatches(): void
    {
        $batch = Bus::batch([new RecordJob('b1'), new RecordJob('b2'), new RecordJob('b3')])
            ->name('import')
            ->dispatch();

        $this->assertSame(3, Bus::findBatch($batch->id)->pendingJobs);

        $this->work();

        $batch = Bus::findBatch($batch->id);
        $this->assertInstanceOf(Batch::class, $batch);
        $this->assertSame(0, $batch->pendingJobs);
        $this->assertTrue($batch->finished());
        $this->assertSame(100, $batch->progress());
        $this->assertSame(1, Cache::store('database')->get('job:b3'));

        $this->artisan('queue:prune-batches', ['--hours' => 0])->assertSuccessful();
    }

    public function testSessionHandler(): void
    {
        $handler = new DatabaseSessionHandler(DB::connection(), 'sessions', 120, $this->app);
        $id = Str::random(40);

        $this->assertSame('', $handler->read($id));

        $this->assertTrue($handler->write($id, 'first'));
        $this->assertSame('first', $handler->read($id));

        // A second handler does not know the row exists: insert fails on the
        // primary key and falls back to update.
        $other = new DatabaseSessionHandler(DB::connection(), 'sessions', 120, $this->app);
        $this->assertTrue($other->write($id, 'second'));
        $this->assertSame('second', $handler->read($id));
        $this->assertSame(1, DB::table('sessions')->count());

        $this->assertTrue($handler->destroy($id));
        $this->assertSame('', $handler->read($id));

        $handler->write('old-session', 'x');
        DB::table('sessions')->where('id', 'old-session')->update(['last_activity' => now()->subHours(3)->getTimestamp()]);
        $handler->gc(120 * 60);
        $this->assertSame(0, DB::table('sessions')->where('id', 'old-session')->count());
    }

    public function testSessionStore(): void
    {
        $store = $this->app['session']->driver('database');
        $store->start();
        $store->put('cart', ['item' => 3]);
        $store->save();

        $reloaded = $this->app['session']->driver('database');
        $reloaded->setId($store->getId());
        $reloaded->start();

        $this->assertSame(['item' => 3], $reloaded->get('cart'));
    }
}
