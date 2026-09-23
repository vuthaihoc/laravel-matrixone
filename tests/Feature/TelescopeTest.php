<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\EntryUpdate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\IncomingExceptionEntry;
use Laravel\Telescope\Storage\DatabaseEntriesRepository;
use Laravel\Telescope\Storage\EntryQueryOptions;
use RuntimeException;

/**
 * Laravel Telescope's database repository storing entries in MatrixOne,
 * using Telescope's own migration unchanged.
 */
class TelescopeTest extends TestCase
{
    /** @var Migration */
    private $migration;

    private DatabaseEntriesRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require __DIR__.'/../../vendor/laravel/telescope/database/migrations/2018_08_08_100000_create_telescope_entries_table.php';
        $this->migration->down();
        $this->migration->up();

        $this->repository = new DatabaseEntriesRepository('matrixone', 100);
    }

    protected function tearDown(): void
    {
        $this->migration->down();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function entry(string $type, array $content, array $tags = [], ?string $familyHash = null): IncomingEntry
    {
        return IncomingEntry::make($content)
            ->batchId($batch = '00000000-0000-0000-0000-000000000001')
            ->type($type)
            ->tags($tags)
            ->withFamilyHash($familyHash);
    }

    public function testStoreFindAndList(): void
    {
        $request = $this->entry(EntryType::REQUEST, ['uri' => '/users', 'status' => 200], ['user:1']);
        $query = $this->entry(EntryType::QUERY, ['sql' => 'select 1', 'time' => 1.5], ['slow'], 'hash-1');

        $this->repository->store(collect([$request, $query]));

        $this->assertSame(2, DB::table('telescope_entries')->count());
        $this->assertSame(2, DB::table('telescope_entries_tags')->count());

        $found = $this->repository->find($request->uuid);
        $this->assertSame('/users', $found->content['uri']);
        $this->assertSame(['user:1'], $found->jsonSerialize()['tags']);

        $requests = $this->repository->get(EntryType::REQUEST, (new EntryQueryOptions)->limit(10));
        $this->assertCount(1, $requests);

        $this->assertCount(1, $this->repository->get(null, (new EntryQueryOptions)->tag('slow')->limit(10)));
        $this->assertCount(1, $this->repository->get(EntryType::QUERY, (new EntryQueryOptions)->familyHash('hash-1')->limit(10)));
        $this->assertCount(2, $this->repository->get(null, EntryQueryOptions::forBatchId('00000000-0000-0000-0000-000000000001')->limit(10)));
        $this->assertCount(0, $this->repository->get(EntryType::REQUEST, (new EntryQueryOptions)->beforeSequence(DB::table('telescope_entries')->min('sequence'))->limit(10)));
    }

    public function testUpdateEntries(): void
    {
        $job = $this->entry(EntryType::JOB, ['status' => 'pending', 'name' => 'SendMail']);
        $this->repository->store(collect([$job]));

        $this->repository->update(collect([
            (new EntryUpdate($job->uuid, EntryType::JOB, ['status' => 'processed']))->addTags(['done']),
        ]));

        $found = $this->repository->find($job->uuid);
        $this->assertSame('processed', $found->content['status']);
        $this->assertSame('SendMail', $found->content['name']);
        $this->assertContains('done', $found->jsonSerialize()['tags']);
    }

    public function testExceptionOccurrencesAreGrouped(): void
    {
        $content = ['class' => 'RuntimeException', 'file' => 'a.php', 'line' => 1, 'message' => 'boom', 'trace' => []];

        $exception = new RuntimeException('boom');
        $make = fn () => (new IncomingExceptionEntry($exception, $content))
            ->batchId('00000000-0000-0000-0000-000000000001')
            ->type(EntryType::EXCEPTION);

        $this->repository->store(collect([$make()]));
        $this->repository->store(collect([$make()]));

        $hash = $make()->familyHash();
        $entries = DB::table('telescope_entries')->where('family_hash', $hash)->orderBy('sequence')->get();
        $this->assertCount(2, $entries);
        $this->assertSame(0, (int) $entries[0]->should_display_on_index);
        $this->assertSame(2, json_decode($entries[1]->content, true)['occurrences']);
    }

    public function testMonitoringTags(): void
    {
        $this->repository->monitor(['user:1', 'job']);
        $this->repository->monitor(['user:1']);

        $this->assertEqualsCanonicalizing(['user:1', 'job'], $this->repository->monitoring());
        $this->assertTrue($this->repository->isMonitoring(['job']));

        $this->repository->stopMonitoring(['job']);
        $this->assertSame(['user:1'], $this->repository->monitoring());
    }

    public function testPruneAndClear(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');
        $this->repository->store(collect([$this->entry(EntryType::LOG, ['message' => 'old'], ['old'])]));

        Carbon::setTestNow('2026-02-01 00:00:00');
        $this->repository->store(collect([$this->entry(EntryType::LOG, ['message' => 'new'])]));

        $deleted = $this->repository->prune(Carbon::parse('2026-01-15'), false);

        // MatrixOne counts the cascaded tag rows in the affected rows (MySQL
        // does not), so the returned total is 2 here instead of 1.
        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertSame(1, DB::table('telescope_entries')->count());
        // The tag of the pruned entry went with it (ON DELETE CASCADE).
        $this->assertSame(0, DB::table('telescope_entries_tags')->count());

        $this->repository->monitor(['x']);
        $this->repository->clear();
        $this->assertSame(0, DB::table('telescope_entries')->count());
        $this->assertSame(0, DB::table('telescope_monitoring')->count());
    }
}
