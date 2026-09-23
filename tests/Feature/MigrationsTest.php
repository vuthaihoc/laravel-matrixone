<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:wipe')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        $this->artisan('db:wipe')->assertSuccessful();

        parent::tearDown();
    }

    private function path(): string
    {
        return __DIR__.'/database/migrations';
    }

    public function testMigrateRollbackAndFresh(): void
    {
        $this->artisan('migrate', ['--path' => $this->path(), '--realpath' => true])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('migrations'));
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('post_tag'));
        $this->assertSame(4, DB::table('migrations')->count());

        // Running again must be a no-op: hasTable() has to report the
        // migrations table correctly for that.
        $this->artisan('migrate', ['--path' => $this->path(), '--realpath' => true])->assertSuccessful();
        $this->assertSame(4, DB::table('migrations')->count());

        $this->artisan('migrate:rollback', ['--path' => $this->path(), '--realpath' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasTable('posts'));
        $this->assertSame(0, DB::table('migrations')->count());

        $this->artisan('migrate:fresh', ['--path' => $this->path(), '--realpath' => true])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('posts'));

        $this->artisan('migrate:status', ['--path' => $this->path(), '--realpath' => true])->assertSuccessful();
    }

    public function testDbWipeRemovesTablesWithForeignKeys(): void
    {
        $this->artisan('migrate', ['--path' => $this->path(), '--realpath' => true])->assertSuccessful();

        $this->artisan('db:wipe')->assertSuccessful();

        $this->assertSame([], Schema::getTableListing(DB::getDatabaseName()));
    }

    public function testDbShowAndTableCommands(): void
    {
        $this->artisan('migrate', ['--path' => $this->path(), '--realpath' => true])->assertSuccessful();

        $this->artisan('db:show')->assertSuccessful();
        $this->artisan('db:table', ['table' => 'posts'])->assertSuccessful();
    }
}
