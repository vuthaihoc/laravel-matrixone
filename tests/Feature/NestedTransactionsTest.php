<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MatrixOne\NestedTransactionRolledBackException;
use RuntimeException;

class NestedTransactionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('nt_users');
        Schema::create('nt_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('nt_users');

        parent::tearDown();
    }

    private function useMode(string $mode): void
    {
        config()->set('database.connections.matrixone.nested_transactions', $mode);
        DB::purge('matrixone');
    }

    /**
     * The example of https://github.com/dengn/php-tester/issues/2.
     */
    private function outerCatchesInnerFailure(): void
    {
        DB::transaction(function () {
            DB::table('nt_users')->insert(['name' => 'Outer']);

            try {
                DB::transaction(function () {
                    DB::table('nt_users')->insert(['name' => 'Inner']);

                    throw new RuntimeException('inner failure');
                });
            } catch (RuntimeException) {
            }
        });
    }

    public function testFlattenKeepsTheInnerWrites(): void
    {
        $this->outerCatchesInnerFailure();

        // Without savepoints the inner rollback cannot undo "Inner" (MySQL would keep only "Outer").
        $this->assertSame(['Inner', 'Outer'], DB::table('nt_users')->orderBy('name')->pluck('name')->all());
    }

    public function testRollbackOnlyFailsTheOuterCommit(): void
    {
        $this->useMode('rollback_only');

        try {
            $this->outerCatchesInnerFailure();
            $this->fail('The outer commit must fail.');
        } catch (NestedTransactionRolledBackException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, DB::table('nt_users')->count());
        $this->assertSame(0, DB::transactionLevel());

        // The connection is usable again, and a clean transaction commits.
        DB::transaction(function () {
            DB::transaction(fn () => DB::table('nt_users')->insert(['name' => 'Clean']));
        });
        $this->assertSame(['Clean'], DB::table('nt_users')->pluck('name')->all());
    }

    public function testRollbackOnlyResetsAfterAnOuterRollback(): void
    {
        $this->useMode('rollback_only');

        try {
            DB::transaction(function () {
                try {
                    DB::transaction(fn () => throw new RuntimeException('inner'));
                } catch (RuntimeException) {
                }

                throw new RuntimeException('outer');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('outer', $e->getMessage());
        }

        DB::transaction(fn () => DB::table('nt_users')->insert(['name' => 'After']));
        $this->assertSame(['After'], DB::table('nt_users')->pluck('name')->all());
    }

    public function testRollbackOnlyWithManualTransactions(): void
    {
        $this->useMode('rollback_only');

        DB::beginTransaction();
        DB::table('nt_users')->insert(['name' => 'Outer']);
        DB::beginTransaction();
        DB::table('nt_users')->insert(['name' => 'Inner']);
        DB::rollBack();

        try {
            DB::commit();
            $this->fail('The outer commit must fail.');
        } catch (NestedTransactionRolledBackException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, DB::table('nt_users')->count());
    }
}
