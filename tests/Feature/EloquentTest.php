<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MatrixOne\Tests\Feature\Models\Post;
use MatrixOne\Tests\Feature\Models\Tag;
use MatrixOne\Tests\Feature\Models\User;

/**
 * Uses Laravel's own RefreshDatabase trait: MatrixOne has real transactions,
 * so no package-specific testing trait is needed.
 */
class EloquentTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    private function user(string $name = 'alice'): User
    {
        return User::create(['name' => $name, 'email' => "{$name}@example.com", 'password' => 'secret']);
    }

    public function testCrudAndTimestamps(): void
    {
        $user = $this->user();

        $this->assertIsInt($user->id);
        $this->assertNotNull($user->created_at);

        $user->update(['name' => 'Alice']);
        $this->assertSame('Alice', $user->fresh()?->name);

        $user->delete();
        $this->assertNull(User::find($user->id));
    }

    public function testRelationshipsAndEagerLoading(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');

        $alice->posts()->createMany([['title' => 'a1'], ['title' => 'a2'], ['title' => 'a3']]);
        $bob->posts()->create(['title' => 'b1']);

        $users = User::withCount('posts')->orderBy('id')->get();
        $this->assertSame([3, 1], $users->pluck('posts_count')->all());

        // Eager loading with a per-parent limit uses window functions.
        $users = User::with(['posts' => fn ($query) => $query->orderBy('id')->limit(2)])->orderBy('id')->get();
        $this->assertSame(['a1', 'a2'], $users[0]->posts->pluck('title')->all());
        $this->assertSame(['b1'], $users[1]->posts->pluck('title')->all());

        $this->assertSame(['alice'], User::whereHas('posts', fn ($query) => $query->where('title', 'a2'))->pluck('name')->all());

        $post = Post::first();
        $tags = collect(['x', 'y'])->map(fn ($name) => Tag::create(['name' => $name]));
        $post->tags()->sync($tags->pluck('id'));
        $this->assertSame(['x', 'y'], $post->tags()->orderBy('name')->pluck('name')->all());

        $post->tags()->detach($tags[0]->id);
        $this->assertSame(['y'], $post->fresh()?->tags->pluck('name')->all());
    }

    public function testSoftDeletes(): void
    {
        $post = $this->user()->posts()->create(['title' => 'soft']);

        $post->delete();

        $this->assertSame(0, Post::count());
        $this->assertSame(1, Post::withTrashed()->count());

        $post->restore();
        $this->assertSame(1, Post::count());

        $post->forceDelete();
        $this->assertSame(0, Post::withTrashed()->count());
    }

    public function testUpsertStyleHelpers(): void
    {
        $user = User::firstOrCreate(['email' => 'x@example.com'], ['name' => 'x', 'password' => 'p']);
        $this->assertTrue($user->wasRecentlyCreated);

        $same = User::createOrFirst(['email' => 'x@example.com'], ['name' => 'y', 'password' => 'p']);
        $this->assertSame($user->id, $same->id);

        User::updateOrCreate(['email' => 'x@example.com'], ['name' => 'z']);
        $this->assertSame('z', $user->fresh()?->name);

        User::upsert([['email' => 'x@example.com', 'name' => 'w', 'password' => 'p']], ['email'], ['name']);
        $this->assertSame('w', $user->fresh()?->name);
        $this->assertSame(1, User::count());
    }

    public function testJsonCastAndQueries(): void
    {
        $post = $this->user()->posts()->create(['title' => 'j', 'options' => ['theme' => 'dark', 'beta' => true]]);

        // JSON documents are stored normalized, so key order is not preserved.
        $this->assertEquals(['theme' => 'dark', 'beta' => true], $post->fresh()?->options);
        $this->assertSame(1, Post::where('options->theme', 'dark')->count());
        $this->assertSame(1, Post::where('options->beta', true)->count());
    }

    public function testVectorCastAndNearestNeighbours(): void
    {
        $user = $this->user();
        $user->posts()->create(['title' => 'x', 'embedding' => [1, 0, 0]]);
        $user->posts()->create(['title' => 'y', 'embedding' => [0, 1, 0]]);
        $user->posts()->create(['title' => 'xy', 'embedding' => [0.7, 0.7, 0]]);

        $this->assertEqualsWithDelta([1.0, 0.0, 0.0], Post::where('title', 'x')->first()?->embedding, 1e-6);

        $this->assertSame(['x', 'xy', 'y'], Post::nearestTo('embedding', [0.9, 0.1, 0], 3)->pluck('title')->all());
        $this->assertSame(['y'], Post::nearestTo('embedding', [0, 1, 0], 1, 'l2')->pluck('title')->all());

        $close = Post::whereVectorDistanceUsing('cosine', 'embedding', [1, 0, 0], '<', 0.5)->orderBy('title')->pluck('title')->all();
        $this->assertSame(['x', 'xy'], $close);

        $withDistance = Post::select('title')->selectVectorDistanceUsing('l2', 'embedding', [1, 0, 0], 'd')->orderBy('d')->first();
        $this->assertSame('x', $withDistance?->title);
        $this->assertEqualsWithDelta(0.0, (float) $withDistance?->d, 1e-6);
    }

    public function testLaravelBuiltInVectorQueries(): void
    {
        if (! method_exists(Builder::class, 'whereVectorSimilarTo')) {
            $this->markTestSkipped('Laravel vector query methods are not available in this Laravel version.');
        }

        $user = $this->user();
        $user->posts()->create(['title' => 'x', 'embedding' => [1, 0, 0]]);
        $user->posts()->create(['title' => 'y', 'embedding' => [0, 1, 0]]);

        $this->assertSame(['x'], Post::whereVectorSimilarTo('embedding', [1, 0.1, 0], 0.9)->pluck('title')->all());
        $this->assertSame(['y', 'x'], Post::orderByVectorDistance('embedding', [0, 1, 0])->pluck('title')->all());
        $this->assertEqualsWithDelta(0.0, (float) Post::selectVectorDistance('embedding', [1, 0, 0])->orderBy('embedding_distance')->value('embedding_distance'), 1e-6);
    }

    public function testFullTextSearch(): void
    {
        Tag::create(['name' => 'a', 'description' => 'matrixone is fast']);
        Tag::create(['name' => 'b', 'description' => 'something else']);

        $this->assertSame(['a'], Tag::whereFullText('description', 'matrixone')->pluck('name')->all());
    }

    public function testFirstTestInsertIsRolledBack(): void
    {
        $this->user('rollback');

        $this->assertSame(1, User::where('name', 'rollback')->count());
    }

    public function testSecondTestSeesACleanDatabase(): void
    {
        $this->assertSame(0, User::where('name', 'rollback')->count());
        $this->assertSame(1, DB::transactionLevel());
    }
}
