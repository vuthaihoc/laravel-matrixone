<?php

namespace MatrixOne\Tests\Feature;

use Exception;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TransactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('tx_items');
        Schema::create('tx_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tx_items');

        parent::tearDown();
    }

    public function testCommit(): void
    {
        DB::transaction(fn () => DB::table('tx_items')->insert(['name' => 'a']));

        $this->assertSame(1, DB::table('tx_items')->count());
    }

    public function testRollbackOnException(): void
    {
        try {
            DB::transaction(function () {
                DB::table('tx_items')->insert(['name' => 'a']);

                throw new Exception('boom');
            });
        } catch (Exception) {
            //
        }

        $this->assertSame(0, DB::table('tx_items')->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testManualRollback(): void
    {
        DB::beginTransaction();
        DB::table('tx_items')->insert(['name' => 'a']);
        DB::rollBack();

        $this->assertSame(0, DB::table('tx_items')->count());
    }

    public function testNestedTransactionsAreFlattenedIntoTheOuterOne(): void
    {
        DB::beginTransaction();
        DB::table('tx_items')->insert(['name' => 'outer']);

        DB::transaction(fn () => DB::table('tx_items')->insert(['name' => 'inner']));
        $this->assertSame(1, DB::transactionLevel());

        DB::rollBack();

        // The inner "commit" only released a virtual level, so rolling back
        // the outer transaction discards both rows.
        $this->assertSame(0, DB::table('tx_items')->count());
    }

    public function testNestedRollbackDoesNotAbortTheOuterTransaction(): void
    {
        DB::beginTransaction();
        DB::table('tx_items')->insert(['name' => 'outer']);

        DB::beginTransaction();
        DB::table('tx_items')->insert(['name' => 'inner']);
        DB::rollBack();

        $this->assertSame(1, DB::transactionLevel());
        $this->assertSame(2, DB::table('tx_items')->count());

        DB::commit();

        // Without savepoints the inner rollback cannot undo its own writes.
        $this->assertSame(2, DB::table('tx_items')->count());
    }

    public function testAfterCommitCallbacks(): void
    {
        $called = false;

        DB::transaction(function () use (&$called) {
            DB::afterCommit(function () use (&$called) {
                $called = true;
            });
        });

        $this->assertTrue($called);
    }
}
