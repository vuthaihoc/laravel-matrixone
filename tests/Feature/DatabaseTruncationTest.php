<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

/**
 * Laravel's own DatabaseTruncation trait, including tables referenced by
 * foreign keys (which MatrixOne refuses to TRUNCATE).
 */
class DatabaseTruncationTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => __DIR__.'/database/migrations', '--realpath' => true];
    }

    public function testFirstTestWritesRows(): void
    {
        $user = DB::table('users')->insertGetId(['name' => 'a', 'email' => 'a@example.com', 'password' => 'p']);
        DB::table('posts')->insert(['user_id' => $user, 'title' => 't']);

        $this->assertSame(1, DB::table('posts')->count());
    }

    public function testSecondTestStartsEmpty(): void
    {
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('posts')->count());
    }
}
