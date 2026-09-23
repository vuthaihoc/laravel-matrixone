<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueryBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('qb_articles');
        Schema::dropIfExists('qb_posts');
        Schema::dropIfExists('qb_users');

        Schema::create('qb_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->integer('votes')->default(0);
            $table->timestamps();
        });

        Schema::create('qb_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('qb_users')->cascadeOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
        });

        // Kept apart from qb_posts: MatrixOne 4.2.4 panics on inserts into a
        // table that has both a foreign key and a FULLTEXT index.
        Schema::create('qb_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->fullText('title');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('qb_articles');
        Schema::dropIfExists('qb_posts');
        Schema::dropIfExists('qb_users');

        parent::tearDown();
    }

    private function seedUsers(): void
    {
        DB::table('qb_users')->insert([
            ['name' => 'alice', 'email' => 'alice@example.com', 'votes' => 3, 'created_at' => '2024-02-10 08:30:00'],
            ['name' => 'bob', 'email' => 'bob@example.com', 'votes' => 5, 'created_at' => '2024-03-11 09:00:00'],
            ['name' => 'carol', 'email' => 'carol@example.com', 'votes' => 5, 'created_at' => '2025-02-10 10:00:00'],
        ]);
    }

    public function testInsertGetIdAndBasicCrud(): void
    {
        $id = DB::table('qb_users')->insertGetId(['name' => 'dave', 'email' => 'dave@example.com']);

        $this->assertIsInt($id);
        $this->assertSame('dave', DB::table('qb_users')->where('id', $id)->value('name'));

        $this->assertSame(1, DB::table('qb_users')->where('id', $id)->update(['votes' => 10]));
        $this->assertSame(11, (int) DB::table('qb_users')->where('id', $id)->increment('votes') + 10);
        $this->assertSame(11, DB::table('qb_users')->where('id', $id)->value('votes'));

        $this->assertSame(1, DB::table('qb_users')->where('id', $id)->delete());
        $this->assertSame(0, DB::table('qb_users')->count());
    }

    public function testExistsReturnsRealBooleans(): void
    {
        $this->assertFalse(DB::table('qb_users')->exists());
        $this->assertTrue(DB::table('qb_users')->doesntExist());

        $this->seedUsers();

        $this->assertTrue(DB::table('qb_users')->where('name', 'bob')->exists());
        $this->assertFalse(DB::table('qb_users')->where('name', 'nobody')->exists());
    }

    public function testUpsertUpdatesExistingRows(): void
    {
        $this->seedUsers();

        DB::table('qb_users')->upsert([
            ['name' => 'alice2', 'email' => 'alice@example.com', 'votes' => 7],
            ['name' => 'erin', 'email' => 'erin@example.com', 'votes' => 1],
        ], ['email'], ['name', 'votes']);

        $this->assertSame('alice2', DB::table('qb_users')->where('email', 'alice@example.com')->value('name'));
        $this->assertSame(7, DB::table('qb_users')->where('email', 'alice@example.com')->value('votes'));
        $this->assertSame(4, DB::table('qb_users')->count());
    }

    public function testInsertOrIgnore(): void
    {
        $this->seedUsers();

        DB::table('qb_users')->insertOrIgnore(['name' => 'dup', 'email' => 'bob@example.com']);

        $this->assertSame('bob', DB::table('qb_users')->where('email', 'bob@example.com')->value('name'));
    }

    public function testAggregatesGroupingAndPagination(): void
    {
        $this->seedUsers();

        $this->assertSame(13, (int) DB::table('qb_users')->sum('votes'));
        $this->assertSame(5, DB::table('qb_users')->max('votes'));

        $grouped = DB::table('qb_users')->select('votes', DB::raw('count(*) as total'))->groupBy('votes')->orderBy('votes')->get();
        $this->assertSame([3, 5], $grouped->pluck('votes')->all());

        $page = DB::table('qb_users')->orderBy('id')->paginate(2);
        $this->assertSame(3, $page->total());
        $this->assertCount(2, $page->items());
    }

    public function testLikeMatchesCaseInsensitivelyLikeMysql(): void
    {
        $this->seedUsers();

        // MatrixOne's LIKE ignores the _ci collation; the driver uses ILIKE.
        $this->assertSame(['alice'], DB::table('qb_users')->where('email', 'like', 'ALICE%')->pluck('name')->all());
        $this->assertSame(['alice'], DB::table('qb_users')->whereLike('email', 'ALICE%')->pluck('name')->all());
        $this->assertSame([], DB::table('qb_users')->whereLike('email', 'ALICE%', caseSensitive: true)->pluck('name')->all());
        $this->assertSame(2, DB::table('qb_users')->where('email', 'not like', 'ALICE%')->count());
    }

    public function testDateBasedWheres(): void
    {
        $this->seedUsers();

        $this->assertSame(1, DB::table('qb_users')->whereDate('created_at', '2024-02-10')->count());
        $this->assertSame(2, DB::table('qb_users')->whereYear('created_at', 2024)->count());
        $this->assertSame(2, DB::table('qb_users')->whereMonth('created_at', 2)->count());
        $this->assertSame(2, DB::table('qb_users')->whereDay('created_at', 10)->count());
        $this->assertSame(1, DB::table('qb_users')->whereTime('created_at', '>=', '09:30:00')->count());
    }

    public function testJoinsSubqueriesUnionsAndCtes(): void
    {
        $this->seedUsers();
        $bob = DB::table('qb_users')->where('name', 'bob')->value('id');
        DB::table('qb_posts')->insert([['user_id' => $bob, 'title' => 'hello world'], ['user_id' => $bob, 'title' => 'second']]);

        $this->assertSame(2, DB::table('qb_users')->join('qb_posts', 'qb_posts.user_id', '=', 'qb_users.id')->count());
        $this->assertSame(['bob'], DB::table('qb_users')->whereIn('id', DB::table('qb_posts')->select('user_id'))->pluck('name')->all());
        $this->assertSame(1, DB::table('qb_users')->whereExists(fn ($q) => $q->from('qb_posts')->whereColumn('qb_posts.user_id', 'qb_users.id'))->count());
        $this->assertCount(5, DB::table('qb_users')->select('id')->unionAll(DB::table('qb_posts')->select('id'))->get());

        $this->assertSame(1, DB::table('qb_users')->join('qb_posts', 'qb_posts.user_id', '=', 'qb_users.id')
            ->where('qb_posts.title', 'second')->update(['qb_users.votes' => 99]));
        $this->assertSame(99, DB::table('qb_users')->where('id', $bob)->value('votes'));
    }

    public function testGroupLimitForEagerLoading(): void
    {
        $this->seedUsers();

        $rows = DB::table('qb_users')->orderBy('id')->groupLimit(1, 'votes')->get();

        $this->assertCount(2, $rows);
    }

    public function testJsonQueries(): void
    {
        $this->seedUsers();
        $id = DB::table('qb_users')->value('id');

        DB::table('qb_posts')->insert([
            'user_id' => $id,
            'title' => 'json',
            'meta' => json_encode(['a' => ['b' => 1], 'active' => true, 'tags' => ['x', 'y'], 'missing_value' => null]),
        ]);

        $this->assertSame(1, DB::table('qb_posts')->where('meta->a->b', 1)->count());
        $this->assertSame(1, DB::table('qb_posts')->where('meta->active', true)->count());
        $this->assertSame(0, DB::table('qb_posts')->where('meta->active', false)->count());
        $this->assertSame(1, DB::table('qb_posts')->whereJsonContainsKey('meta->a->b')->count());
        $this->assertSame(0, DB::table('qb_posts')->whereJsonDoesntContainKey('meta->a->b')->count());
        $this->assertSame('1', (string) DB::table('qb_posts')->value('meta->a->b'));

        $this->assertSame(1, DB::table('qb_posts')->whereJsonContains('meta->tags', 'x')->count());
        $this->assertSame(1, DB::table('qb_posts')->whereJsonContains('meta->tags', ['x', 'y'])->count());
        $this->assertSame(0, DB::table('qb_posts')->whereJsonDoesntContain('meta->tags', 'x')->count());
        $this->assertSame(1, DB::table('qb_posts')->whereJsonLength('meta->tags', 2)->count());
        $this->assertSame(1, DB::table('qb_posts')->whereJsonLength('meta->tags', '>', 1)->count());
        $this->assertSame(1, DB::table('qb_posts')->whereJsonOverlaps('meta->tags', ['y', 'z'])->count());
        $this->assertSame(0, DB::table('qb_posts')->whereJsonOverlaps('meta->tags', ['z'])->count());
        $this->assertSame(1, DB::table('qb_posts')->whereJsonContainsKey('meta->missing_value')->count());

        DB::table('qb_posts')->update(['meta->a->b' => 2]);
        $this->assertSame(2, json_decode((string) DB::table('qb_posts')->value('meta'), true)['a']['b']);
    }

    public function testInsertGetIdOnATableWithAFullTextIndex(): void
    {
        // MatrixOne's LAST_INSERT_ID() reports wrong values for such tables;
        // the driver reads the key back with INSERT ... RETURNING.
        foreach (['alpha', 'beta', 'gamma'] as $title) {
            $id = DB::table('qb_articles')->insertGetId(['title' => $title]);

            $this->assertSame($title, DB::table('qb_articles')->where('id', $id)->value('title'));
        }
    }

    public function testInsertGetIdMarksTheConnectionAsModified(): void
    {
        DB::connection()->forgetRecordModificationState();

        DB::table('qb_articles')->insertGetId(['title' => 'sticky']);

        $this->assertTrue(DB::connection()->hasModifiedRecords());
    }

    public function testFullTextSearch(): void
    {
        DB::table('qb_articles')->insert([['title' => 'hello world'], ['title' => 'goodbye']]);

        $this->assertSame(1, DB::table('qb_articles')->whereFullText('title', 'hello')->count());
        $this->assertSame(1, DB::table('qb_articles')->whereFullText('title', '+goodbye', ['mode' => 'boolean'])->count());
    }

    public function testLocksAndRandomOrder(): void
    {
        $this->seedUsers();

        DB::transaction(function () {
            $this->assertCount(3, DB::table('qb_users')->lockForUpdate()->get());
            $this->assertCount(3, DB::table('qb_users')->sharedLock()->get());
        });

        $this->assertNotNull(DB::table('qb_users')->inRandomOrder(7)->first());
    }

    public function testChunkingAndCursors(): void
    {
        $this->seedUsers();

        $seen = [];
        DB::table('qb_users')->orderBy('id')->chunkById(2, function ($users) use (&$seen) {
            foreach ($users as $user) {
                $seen[] = $user->name;
            }
        });

        $this->assertSame(['alice', 'bob', 'carol'], $seen);
        $this->assertSame(3, iterator_count(DB::table('qb_users')->cursor()));
    }

    public function testTruncate(): void
    {
        $this->seedUsers();

        DB::table('qb_posts')->truncate();
        Schema::disableForeignKeyConstraints();
        DB::table('qb_users')->truncate();
        Schema::enableForeignKeyConstraints();

        $this->assertSame(0, DB::table('qb_users')->count());
    }
}
