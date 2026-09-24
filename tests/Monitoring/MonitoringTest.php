<?php

namespace MatrixOne\Tests\Monitoring;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use MatrixOne\MatrixOneConnection;
use MatrixOne\Monitoring\ExecutionPlan;
use MatrixOne\Monitoring\StatementLogQuery;
use MatrixOne\Tests\Feature\TestCase;

/**
 * MatrixOne publishes statements to system.statement_info a few seconds
 * after they run, so these tests wait and are slow: they form their own
 * suite, excluded from `composer test` (run `composer test:monitoring`).
 */
class MonitoringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('mon_items');
        Schema::create('mon_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('qty');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('mon_items');

        parent::tearDown();
    }

    private function connection(): MatrixOneConnection
    {
        /** @var MatrixOneConnection */
        return DB::connection();
    }

    /**
     * Wait until MatrixOne has published the statement tagged $tag: usually
     * a few seconds after it ran, sometimes more than 15.
     */
    private function waitForStatement(string $tag, ?callable $scope = null): object
    {
        $deadline = microtime(true) + 60;

        while (microtime(true) < $deadline) {
            $query = $this->connection()->statementLog()->since('10m')->summary()
                ->whereLike('statement', "%{$tag}%")
                ->whereNotLike('statement', '%statement_info%');

            if ($scope) {
                $scope($query);
            }

            if ($row = $query->first()) {
                return $row;
            }

            usleep(500_000);
        }

        $this->fail("Statement [{$tag}] was not recorded.");
    }

    public function testDefaultFilters(): void
    {
        $query = $this->connection()->statementLog();
        $sql = $query->toSql();

        $this->assertInstanceOf(StatementLogQuery::class, $query);
        $this->assertStringContainsString('from `system`.`statement_info`', $sql);
        $this->assertStringContainsString("`database` = 'laravel_matrixone_test'", $sql);
        $this->assertStringContainsString("`sql_source_type` = 'external_sql'", $sql);
        $this->assertStringContainsString('`request_at` >= utc_timestamp() - interval 3600 second', $sql);

        $sql = $this->connection()->statementLog()->allDatabases()->includeInternal()
            ->since(CarbonImmutable::parse('2026-01-01 07:00:00', 'Asia/Ho_Chi_Minh'))->toSql();

        $this->assertStringNotContainsString('`database` =', $sql);
        $this->assertStringNotContainsString('sql_source_type', $sql);
        $this->assertStringContainsString("`request_at` >= '2026-01-01 00:00:00.000000'", $sql);

        $this->expectException(InvalidArgumentException::class);
        $this->connection()->statementLog()->since('1 week');
    }

    public function testSlowStatementsAndTheirPlans(): void
    {
        // MatrixOne keeps plans of statements running for at least a second.
        $tag = 'mon_slow_'.bin2hex(random_bytes(4));
        DB::select("select sleep(1.1) as s, '{$tag}' as tag");

        $row = $this->waitForStatement($tag, fn ($query) => $query->slowerThan(1000));

        $this->assertSame('Select', $row->statement_type);
        $this->assertSame('Success', $row->status);
        $this->assertGreaterThanOrEqual(1000, (float) $row->duration_ms);

        $plan = $this->connection()->getStatementPlan($row->statement_id);
        $this->assertInstanceOf(ExecutionPlan::class, $plan);
        $this->assertContains('Project', array_column($plan->nodes(), 'name'));
        $this->assertGreaterThanOrEqual(1000, max(array_column($plan->nodes(), 'time_ms')));

        // A faster statement keeps none. (Fast statements of one shape may be
        // merged into a single row, so pick any recent unmerged one.)
        $fast = $this->connection()->statementLog()->since('1d')
            ->where('duration', '<', 100_000_000)->where('aggr_count', 0)->value('statement_id');
        $this->assertIsString($fast);
        $this->assertNull($this->connection()->getStatementPlan($fast));

        $this->assertNull($this->connection()->getStatementPlan('00000000-0000-0000-0000-000000000000'));

        // Faster than the threshold: not returned.
        $this->assertNull(
            $this->connection()->statementLog()->since('10m')->slowerThan(60_000)->whereLike('statement', "%{$tag}%")->first()
        );
    }

    public function testFailedStatements(): void
    {
        $tag = 'mon_missing_'.bin2hex(random_bytes(4));

        try {
            DB::select("select * from {$tag}");
            $this->fail('The query should fail.');
        } catch (QueryException) {
        }

        $row = $this->waitForStatement($tag, fn ($query) => $query->failed());

        $this->assertSame('Failed', $row->status);
        $this->assertNotEmpty($row->err_code);
        $this->assertStringContainsString($tag, (string) $row->error);
    }

    public function testSlowQueriesCommand(): void
    {
        $tag = 'mon_cmd_'.bin2hex(random_bytes(4));
        DB::select("select sleep(1.1) as s, '{$tag}' as tag");
        $row = $this->waitForStatement($tag, fn ($query) => $query->slowerThan(1000));

        $this->artisan('matrixone:slow-queries', ['--since' => '10m', '--type' => ['Select']])
            ->expectsOutputToContain($tag)
            ->assertSuccessful();

        $this->artisan('matrixone:slow-queries', ['--plan' => $row->statement_id])
            ->expectsOutputToContain('Project')
            ->assertSuccessful();

        $this->artisan('matrixone:slow-queries', ['--since' => '1 week'])->assertFailed();
        $this->artisan('matrixone:slow-queries', ['--failed' => true, '--since' => '10m'])->assertSuccessful();
    }

    public function testTableStats(): void
    {
        DB::table('mon_items')->insert([
            ['name' => 'apple', 'qty' => 3],
            ['name' => 'pear', 'qty' => 10],
        ]);

        $stats = $this->connection()->tableStats('mon_items');

        $this->assertSame(3, $stats['columns']);
        $this->assertEquals(['min' => 3, 'max' => 10], $stats['values']['qty']);
        $this->assertEquals(['min' => 'apple', 'max' => 'pear'], $stats['values']['name']);
        // Refreshed asynchronously: 0 right after the insert, 2 about a minute later.
        $this->assertContains($stats['rows'], [0, 2]);
        $this->assertGreaterThanOrEqual(0, $stats['size']);

        $this->assertSame([], $this->connection()->tableStats('mon_items', values: false)['values']);
    }
}
