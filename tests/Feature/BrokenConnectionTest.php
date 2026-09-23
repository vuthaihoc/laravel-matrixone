<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MatrixOne\Schema\Blueprint;

/**
 * When MatrixOne panics while executing a statement it leaves the PDO
 * connection mid-response, while the server keeps the session's transaction
 * and row locks. The driver drops such a connection so later queries (and
 * other sessions writing the same rows) are not blocked.
 */
class BrokenConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('bc_posts');
        Schema::dropIfExists('bc_users');
        Schema::create('bc_users', fn (Blueprint $table) => $table->id());
        Schema::create('bc_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bc_user_id');
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('bc_posts');
        Schema::dropIfExists('bc_users');

        parent::tearDown();
    }

    public function testTheConnectionRecoversAfterAServerPanicInsideATransaction(): void
    {
        $userId = DB::table('bc_users')->insertGetId([]);
        DB::table('bc_posts')->insert(['bc_user_id' => $userId]);

        DB::beginTransaction();
        DB::table('bc_users')->where('id', $userId)->update(['id' => $userId]);

        // A correlated count with a point lookup crashes MatrixOne 4.2.4
        // (see tests/KnownIssues). On a fixed server it simply succeeds.
        $sql = 'select `id`, (select count(*) from `bc_posts` where `bc_users`.`id` = `bc_posts`.`bc_user_id` '
            .'and `bc_posts`.`deleted_at` is null) as `c` from `bc_users` where `bc_users`.`id` = ?';

        try {
            $this->assertSame(1, DB::selectOne($sql, [$userId])->c);
            DB::rollBack();
        } catch (QueryException) {
            // The broken connection was dropped and its transaction forgotten.
            $this->assertSame(0, DB::transactionLevel());
        }

        // The same connection works again and the row is not locked anymore.
        $this->assertSame(1, DB::table('bc_users')->count());
        $this->assertSame(1, DB::table('bc_users')->where('id', $userId)->update(['id' => $userId]));
    }
}
