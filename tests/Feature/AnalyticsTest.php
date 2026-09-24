<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use MatrixOne\MatrixOneConnection;

class Metric extends Model
{
    protected $table = 'analytics_metrics';

    public $timestamps = false;

    protected $guarded = [];
}

class AnalyticsTest extends TestCase
{
    /** @var list<string> */
    private array $snapshots = ['analytics_db', 'analytics_tbl', 'analytics-dash', 'analytics_cmd'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanUp();

        Schema::create('analytics_metrics', function (Blueprint $table) {
            $table->id();
            $table->dateTime('ts', 3);
            $table->string('device');
            $table->double('v');
        });

        DB::table('analytics_metrics')->insert([
            ['ts' => '2026-01-01 00:00:01', 'device' => 'a', 'v' => 1],
            ['ts' => '2026-01-01 00:00:04', 'device' => 'b', 'v' => 2],
            ['ts' => '2026-01-01 00:00:12', 'device' => 'a', 'v' => 3],
            ['ts' => '2026-01-01 00:00:31', 'device' => 'a', 'v' => 4],
            ['ts' => '2026-01-01 00:00:40', 'device' => 'b', 'v' => 5],
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();

        parent::tearDown();
    }

    private function cleanUp(): void
    {
        foreach ($this->snapshots as $snapshot) {
            $this->connection()->dropSnapshot($snapshot);
        }

        $this->connection()->dropPitr('analytics_pitr');
        Schema::dropIfExists('analytics_metrics');
        Schema::dropIfExists('analytics_clustered');
    }

    private function connection(): MatrixOneConnection
    {
        /** @var MatrixOneConnection */
        return DB::connection();
    }

    public function testTimeWindowAggregates(): void
    {
        $rows = DB::table('analytics_metrics')
            ->select('_wstart', DB::raw('sum(v) as total'))
            ->timeWindow('ts', '10 seconds')
            ->orderBy('_wstart')
            ->get();

        $this->assertSame([3.0, 3.0, 4.0, 5.0], $rows->pluck('total')->map(fn ($v) => (float) $v)->all());
        $this->assertStringStartsWith('2026-01-01 00:00:00', (string) $rows->first()?->_wstart);
    }

    public function testTimeWindowWithFiltersSlidingAndFill(): void
    {
        $rows = DB::table('analytics_metrics')
            ->select('_wstart', '_wend', DB::raw('max(v) as peak'))
            ->where('device', 'a')
            ->timeWindow('ts', '10 seconds', sliding: '5 seconds', fill: 'prev')
            ->get();

        $this->assertNotEmpty($rows);
        $this->assertSame(4.0, (float) $rows->max('peak'));
    }

    public function testTimeWindowCountsAndPaginatesWindows(): void
    {
        $query = DB::table('analytics_metrics')->select('_wstart', DB::raw('count(*) as n'))->timeWindow('ts', '10 seconds');

        $this->assertSame(4, (clone $query)->count());
        $this->assertSame(5, (int) (clone $query)->sum('n'));

        $page = (clone $query)->orderBy('_wstart')->paginate(2, page: 2);
        $this->assertSame(4, $page->total());
        $this->assertSame([1, 1], $page->getCollection()->pluck('n')->map(fn ($n) => (int) $n)->all());
    }

    public function testSample(): void
    {
        $this->assertCount(2, DB::table('analytics_metrics')->sample(2)->get());
        $this->assertCount(2, Metric::query()->sample(2)->get());
        $this->assertSame(2, Metric::query()->sample(2)->count());

        $sampled = DB::table('analytics_metrics')->where('device', 'a')->sample(10)->pluck('device')->unique()->all();
        $this->assertSame(['a'], array_values($sampled));

        $this->assertLessThanOrEqual(5, DB::table('analytics_metrics')->samplePercent(50)->count());
    }

    public function testSamplePerGroup(): void
    {
        $rows = DB::table('analytics_metrics')->select('device')->sample(1, 'v')->groupBy('device')->get();

        $this->assertEqualsCanonicalizing(['a', 'b'], $rows->pluck('device')->all());
    }

    public function testSnapshotsAndTimeTravel(): void
    {
        $this->connection()->createSnapshot('analytics_db');
        $this->connection()->createSnapshot('analytics_tbl', 'analytics_metrics');

        DB::table('analytics_metrics')->where('device', 'b')->delete();

        $this->assertSame(3, DB::table('analytics_metrics')->count());
        $this->assertSame(5, DB::table('analytics_metrics')->asOfSnapshot('analytics_db')->count());
        $this->assertSame(5, Metric::query()->asOfSnapshot('analytics_tbl')->count());
        $this->assertSame(2, DB::table('analytics_metrics as m')->asOfSnapshot('analytics_db')->where('m.device', 'b')->count());

        // Rows deleted since the snapshot, by joining the past with the present.
        $deleted = DB::table('analytics_metrics')->asOfSnapshot('analytics_db')
            ->whereNotIn('id', DB::table('analytics_metrics')->select('id'))
            ->pluck('device');
        $this->assertSame(['b', 'b'], $deleted->all());

        // Restore them (RESTORE ... FROM SNAPSHOT is a syntax error on 4.2.4).
        DB::table('analytics_metrics')->insertUsing(
            ['id', 'ts', 'device', 'v'],
            DB::table('analytics_metrics')->asOfSnapshot('analytics_db')
                ->select('id', 'ts', 'device', 'v')
                ->whereNotIn('id', DB::table('analytics_metrics')->select('id'))
        );
        $this->assertSame(5, DB::table('analytics_metrics')->count());

        $this->assertTrue($this->connection()->hasSnapshot('analytics_tbl'));
        $levels = collect($this->connection()->getSnapshots())->whereIn('snapshot_name', ['analytics_db', 'analytics_tbl'])->pluck('snapshot_level', 'snapshot_name');
        $this->assertSame(['analytics_db' => 'database', 'analytics_tbl' => 'table'], $levels->sortKeys()->all());

        $this->connection()->dropSnapshot('analytics_tbl');
        $this->assertFalse($this->connection()->hasSnapshot('analytics_tbl'));
    }

    public function testSnapshotNames(): void
    {
        $this->connection()->createSnapshot('analytics-dash');
        $this->assertSame(5, DB::table('analytics_metrics')->asOfSnapshot('analytics-dash')->count());

        $this->expectException(InvalidArgumentException::class);
        $this->connection()->createSnapshot("analytics_it's");
    }

    public function testAsOfTimestamp(): void
    {
        $before = (string) DB::scalar('select now(6)');
        usleep(300_000);

        DB::table('analytics_metrics')->delete();

        $this->assertSame(0, DB::table('analytics_metrics')->count());
        $this->assertSame(5, DB::table('analytics_metrics')->asOfTimestamp($before)->count());
    }

    public function testPitr(): void
    {
        $this->connection()->createPitr('analytics_pitr', 1, 'd', 'analytics_metrics');
        $this->connection()->alterPitr('analytics_pitr', 2, 'h');

        $pitr = collect($this->connection()->getPitrs())->firstWhere('pitr_name', 'analytics_pitr');
        $this->assertSame('table', $pitr['pitr_level'] ?? null);
        $this->assertSame('h', $pitr['pitr_unit'] ?? null);

        $this->expectException(InvalidArgumentException::class);
        $this->connection()->createPitr('analytics_pitr', 1, 'w');
    }

    public function testSnapshotAndPitrCommands(): void
    {
        $this->artisan('matrixone:snapshot', ['action' => 'create', 'name' => 'analytics_cmd', '--table' => 'analytics_metrics'])->assertSuccessful();
        $this->assertTrue($this->connection()->hasSnapshot('analytics_cmd'));
        $this->artisan('matrixone:snapshot')->assertSuccessful();
        $this->artisan('matrixone:snapshot', ['action' => 'drop', 'name' => 'analytics_cmd'])->assertSuccessful();
        $this->assertFalse($this->connection()->hasSnapshot('analytics_cmd'));

        $this->artisan('matrixone:pitr', ['action' => 'create', 'name' => 'analytics_pitr', '--range' => '3d'])->assertSuccessful();
        $this->artisan('matrixone:pitr', ['action' => 'create', 'name' => 'x', '--range' => '3w'])->assertFailed();
        $this->artisan('matrixone:pitr')->assertSuccessful();
        $this->artisan('matrixone:pitr', ['action' => 'drop', 'name' => 'analytics_pitr'])->assertSuccessful();
        $this->assertNull(collect($this->connection()->getPitrs())->firstWhere('pitr_name', 'analytics_pitr'));
    }

    public function testClusterBy(): void
    {
        Schema::create('analytics_clustered', function (Blueprint $table) {
            $table->dateTime('ts');
            $table->string('device');
            $table->double('v');
            $table->unique(['device', 'ts']);
            $table->clusterBy(['device', 'ts']);
        });

        $create = (array) DB::selectOne('show create table analytics_clustered');
        $this->assertStringContainsString('CLUSTER BY (`device`, `ts`)', (string) array_values($create)[1]);

        DB::table('analytics_clustered')->insert(['ts' => '2026-01-01 00:00:00', 'device' => 'a', 'v' => 1]);
        $this->assertSame(1, DB::table('analytics_clustered')->count());
    }
}
