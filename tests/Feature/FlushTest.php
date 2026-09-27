<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MatrixOne\MatrixOneConnection;

class FlushTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['flush_orders', 'flush_logs'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('note');
            });

            // Small inserts stay in memory (and the WAL) until flushed.
            foreach (range(1, 5) as $i) {
                DB::table($table)->insert(['note' => "row {$i}"]);
            }
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('flush_orders');
        Schema::dropIfExists('flush_logs');

        parent::tearDown();
    }

    private function connection(): MatrixOneConnection
    {
        /** @var MatrixOneConnection */
        return DB::connection();
    }

    private function persistedRows(string $table): int
    {
        return (int) DB::scalar(
            "select coalesce(sum(rows_cnt), 0) from metadata_scan('laravel_matrixone_test.{$table}', 'id') g"
        );
    }

    public function testFlushWritesOneTableToObjectStorage(): void
    {
        $this->assertSame(0, $this->persistedRows('flush_orders'));

        $this->connection()->flushTable('flush_orders');

        $this->assertSame(5, $this->persistedRows('flush_orders'));
        $this->assertSame(0, $this->persistedRows('flush_logs'));
    }

    public function testCheckpointWritesEveryTable(): void
    {
        $this->connection()->checkpoint();

        $this->assertSame(5, $this->persistedRows('flush_orders'));
        $this->assertSame(5, $this->persistedRows('flush_logs'));
    }

    public function testCommand(): void
    {
        $this->artisan('matrixone:flush', ['tables' => ['flush_orders', 'flush_logs']])
            ->expectsOutputToContain('flush_orders')
            ->assertSuccessful();
        $this->assertSame(5, $this->persistedRows('flush_logs'));

        $this->artisan('matrixone:flush')->assertFailed();
        $this->artisan('matrixone:flush', ['tables' => ['missing_table']])->assertFailed();
        $this->artisan('matrixone:flush', ['--checkpoint' => true])->expectsOutputToContain('checkpoint')->assertSuccessful();
    }

    public function testUnknownTablesFail(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no such table');

        $this->connection()->flushTable('missing_table');
    }
}
