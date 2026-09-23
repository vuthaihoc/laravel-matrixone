<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MatrixOne\Tests\Feature\Models\Comment;
use MatrixOne\Tests\Feature\Models\Country;
use MatrixOne\Tests\Feature\Models\Post;
use MatrixOne\Tests\Feature\Models\Profile;
use MatrixOne\Tests\Feature\Models\Tag;
use MatrixOne\Tests\Feature\Models\User;
use MatrixOne\Tests\Feature\Models\Video;

/**
 * Every Eloquent relationship type and relationship query, run against a
 * real MatrixOne server.
 */
class RelationshipTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    private function user(string $name, ?Country $country = null): User
    {
        return User::create([
            'name' => $name,
            'email' => "{$name}@example.com",
            'password' => 'secret',
            'country_id' => $country?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPost(User $user, string $title, array $attributes = []): Post
    {
        return $user->posts()->create(['title' => $title] + $attributes);
    }

    public function testBelongsTo(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $post = $this->createPost($alice, 'p1');

        $this->assertSame('alice', $post->user->name);
        $this->assertSame('alice', Post::with('user')->first()->user->name);
        $this->assertSame(1, Post::whereBelongsTo($alice)->count());

        $post->user()->associate($bob)->save();
        $this->assertSame('bob', $post->fresh()->user->name);

        $this->assertSame(['p1'], Post::whereRelation('user', 'name', 'bob')->pluck('title')->all());
    }

    public function testHasOne(): void
    {
        $alice = $this->user('alice');
        $this->user('bob');

        $alice->profile()->create(['bio' => 'hello']);

        $this->assertSame('hello', $alice->profile->bio);
        $this->assertSame('alice', Profile::first()->user->name);
        $this->assertSame(['alice'], User::has('profile')->pluck('name')->all());
        $this->assertSame(['bob'], User::doesntHave('profile')->pluck('name')->all());

        $users = User::with('profile')->orderBy('name')->get();
        $this->assertSame('hello', $users[0]->profile->bio);
        $this->assertNull($users[1]->profile);

        $alice->profile()->updateOrCreate([], ['bio' => 'updated']);
        $this->assertSame('updated', $alice->fresh()->profile->bio);
        $this->assertSame(1, Profile::count());
    }

    public function testOneOfMany(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');

        Carbon::setTestNow('2026-01-01 00:00:00');
        $this->createPost($alice, 'old', ['views' => 50]);
        Carbon::setTestNow('2026-02-01 00:00:00');
        $this->createPost($alice, 'popular', ['views' => 900]);
        Carbon::setTestNow('2026-03-01 00:00:00');
        $this->createPost($alice, 'new', ['views' => 10]);
        $this->createPost($bob, 'only', ['views' => 1]);
        Carbon::setTestNow();

        $this->assertSame('new', $alice->latestPost->title);
        $this->assertSame('old', $alice->oldestPost->title);
        $this->assertSame('popular', $alice->mostViewedPost->title);

        $users = User::with(['latestPost', 'oldestPost', 'mostViewedPost'])->orderBy('name')->get();
        $this->assertSame(['new', 'only'], $users->pluck('latestPost.title')->all());
        $this->assertSame(['old', 'only'], $users->pluck('oldestPost.title')->all());
        $this->assertSame(['popular', 'only'], $users->pluck('mostViewedPost.title')->all());

        $this->assertSame(['alice'], User::whereHas('latestPost', fn ($q) => $q->where('title', 'new'))->pluck('name')->all());
    }

    public function testHasManyThroughAndHasOneThrough(): void
    {
        $vn = Country::create(['name' => 'Vietnam']);
        $jp = Country::create(['name' => 'Japan']);

        $alice = $this->user('alice', $vn);
        $bob = $this->user('bob', $vn);
        $carol = $this->user('carol', $jp);

        $this->createPost($alice, 'a1');
        $this->createPost($bob, 'b1');
        $this->createPost($bob, 'b2');
        $this->createPost($carol, 'c1');

        $this->assertSame(['a1', 'b1', 'b2'], $vn->posts()->orderBy('title')->pluck('title')->all());
        $this->assertSame([3, 1], Country::withCount('posts')->orderBy('id')->pluck('posts_count')->all());
        $this->assertSame(['Vietnam'], Country::whereHas('posts', fn ($q) => $q->where('title', 'b2'))->pluck('name')->all());

        $countries = Country::with('posts')->orderBy('id')->get();
        $this->assertCount(3, $countries[0]->posts);

        $this->assertNotNull($vn->latestPost);
        $this->assertSame('c1', $jp->latestPost->title);
    }

    public function testPolymorphicOneToMany(): void
    {
        $alice = $this->user('alice');
        $post = $this->createPost($alice, 'p1');
        $video = Video::create(['title' => 'v1']);

        $post->comments()->createMany([['body' => 'nice post'], ['body' => 'second']]);
        $video->comments()->create(['body' => 'nice video']);

        $this->assertSame(2, $post->comments()->count());
        $this->assertSame(['nice video'], $video->comments->pluck('body')->all());

        $comments = Comment::with('commentable')->orderBy('id')->get();
        $this->assertInstanceOf(Post::class, $comments[0]->commentable);
        $this->assertInstanceOf(Video::class, $comments[2]->commentable);
        $this->assertSame('v1', $comments[2]->commentable->title);

        $this->assertSame(['nice video'], Comment::whereHasMorph('commentable', [Video::class], fn ($q) => $q->where('title', 'v1'))->pluck('body')->all());
        $this->assertSame(2, Comment::whereMorphedTo('commentable', $post)->count());
        $this->assertSame('second', $post->latestComment->body);
        $this->assertSame([2], Post::withCount('comments')->pluck('comments_count')->all());
    }

    public function testPolymorphicManyToMany(): void
    {
        $post = $this->createPost($this->user('alice'), 'p1');
        $video = Video::create(['title' => 'v1']);
        [$red, $blue] = [Tag::create(['name' => 'red']), Tag::create(['name' => 'blue'])];

        $video->tags()->attach([$red->id, $blue->id]);
        $post->morphTags()->sync([$red->id]);

        $this->assertSame(['blue', 'red'], $video->tags()->orderBy('name')->pluck('name')->all());
        $this->assertSame(['v1'], $red->videos->pluck('title')->all());
        $this->assertSame(['p1'], $red->taggedPosts->pluck('title')->all());
        $this->assertSame([], $blue->taggedPosts->pluck('title')->all());

        $tags = Tag::withCount(['videos', 'taggedPosts'])->orderBy('name')->get();
        $this->assertSame([1, 1], $tags->pluck('videos_count')->all());
        $this->assertSame([0, 1], $tags->pluck('tagged_posts_count')->all());

        $video->tags()->detach($blue->id);
        $this->assertSame(['red'], $video->tags()->pluck('name')->all());
    }

    public function testPivotData(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $p1 = $this->createPost($alice, 'p1');
        $p2 = $this->createPost($alice, 'p2');

        $bob->likedPosts()->attach($p1->id, ['rating' => 5]);
        $bob->likedPosts()->attach([$p2->id => ['rating' => 2]]);

        $liked = $bob->likedPosts()->orderBy('title')->get();
        $this->assertSame([5, 2], $liked->pluck('pivot.rating')->all());
        $this->assertNotNull($liked[0]->pivot->created_at);

        $this->assertSame(['p1'], $bob->likedPosts()->wherePivot('rating', '>=', 4)->pluck('title')->all());
        $this->assertSame(['p2', 'p1'], $bob->likedPosts()->orderByPivot('rating')->pluck('title')->all());

        $bob->likedPosts()->updateExistingPivot($p2->id, ['rating' => 4]);
        $this->assertSame(4, $bob->likedPosts()->find($p2->id)->pivot->rating);

        $bob->likedPosts()->syncWithoutDetaching([$p1->id => ['rating' => 3]]);
        $this->assertSame(3, $bob->likedPosts()->find($p1->id)->pivot->rating);

        $bob->likedPosts()->toggle([$p1->id]);
        $this->assertSame(['p2'], $bob->likedPosts()->pluck('title')->all());

        $this->assertSame(['bob'], $p2->likers->pluck('name')->all());
        $this->assertSame(4.0, (float) $p2->likers()->avg('likes.rating'));
    }

    public function testRelationshipAggregatesAndExistence(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $this->user('carol');

        $this->createPost($alice, 'a1', ['views' => 10]);
        $this->createPost($alice, 'a2', ['views' => 30]);
        $this->createPost($bob, 'b1', ['views' => 5]);

        $users = User::withCount('posts')
            ->withSum('posts', 'views')
            ->withMax('posts', 'views')
            ->withMin('posts', 'views')
            ->withAvg('posts', 'views')
            ->withExists('posts')
            ->orderBy('name')
            ->get();

        $this->assertSame([2, 1, 0], $users->pluck('posts_count')->all());
        $this->assertEquals([40, 5, null], $users->pluck('posts_sum_views')->all());
        $this->assertEquals([30, 5, null], $users->pluck('posts_max_views')->all());
        $this->assertEquals([10, 5, null], $users->pluck('posts_min_views')->all());
        $this->assertEqualsWithDelta(20.0, (float) $users[0]->posts_avg_views, 1e-6);
        $this->assertSame([true, true, false], $users->pluck('posts_exists')->map(fn ($v) => (bool) $v)->all());

        $this->assertSame(['alice'], User::has('posts', '>=', 2)->pluck('name')->all());
        $this->assertSame(['carol'], User::whereDoesntHave('posts')->pluck('name')->all());
        $this->assertSame(['alice', 'bob'], User::whereHas('posts', fn ($q) => $q->where('views', '>', 1))->orderBy('name')->pluck('name')->all());
        $this->assertSame(['alice', 'carol'], User::whereRelation('posts', 'views', '>', 20)->orWhereDoesntHave('posts')->orderBy('name')->pluck('name')->all());
        $this->assertSame(1, User::withWhereHas('posts', fn ($q) => $q->where('views', 30))->count());
    }

    public function testNestedRelationshipQueries(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $post = $this->createPost($alice, 'a1');
        $this->createPost($bob, 'b1');
        $tag = Tag::create(['name' => 'php']);
        $post->tags()->attach($tag);
        $post->comments()->create(['body' => 'hi']);

        $this->assertSame(['alice'], User::whereHas('posts.tags', fn ($q) => $q->where('name', 'php'))->pluck('name')->all());
        $this->assertSame(['alice'], User::has('posts.comments')->pluck('name')->all());

        $users = User::with(['posts.tags', 'posts.comments'])->orderBy('name')->get();
        $this->assertSame(['php'], $users[0]->posts[0]->tags->pluck('name')->all());
        $this->assertCount(1, $users[0]->posts[0]->comments);
        $this->assertCount(0, $users[1]->posts[0]->tags);
    }

    public function testEagerLoadingLimitsPerParent(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');

        foreach (['a1', 'a2', 'a3'] as $title) {
            $this->createPost($alice, $title);
        }
        $this->createPost($bob, 'b1');

        $tags = collect(['t1', 't2', 't3'])->map(fn ($name) => Tag::create(['name' => $name]));
        Post::all()->each(fn (Post $post) => $post->tags()->sync($tags->pluck('id')));

        $users = User::with(['posts' => fn ($q) => $q->orderByDesc('id')->limit(2)])->orderBy('name')->get();
        $this->assertSame(['a3', 'a2'], $users[0]->posts->pluck('title')->all());
        $this->assertSame(['b1'], $users[1]->posts->pluck('title')->all());

        // Per-parent limits on a BelongsToMany use a window over the pivot.
        $posts = Post::with(['tags' => fn ($q) => $q->orderBy('name')->limit(1)])->orderBy('id')->get();
        $this->assertSame(['t1'], $posts[0]->tags->pluck('name')->all());
        $this->assertSame(['t1'], $posts[3]->tags->pluck('name')->all());
    }

    public function testLazyEagerLoading(): void
    {
        // Two parents: loadCount() on a single soft-deletable parent triggers a
        // MatrixOne 4.2.4 panic, covered by tests/KnownIssues.
        $this->user('bob');
        $alice = $this->user('alice');
        $post = $this->createPost($alice, 'a1');
        $post->comments()->create(['body' => 'c']);
        $video = Video::create(['title' => 'v']);
        $video->comments()->create(['body' => 'vc']);

        $users = User::orderBy('name')->get();
        $users->load('posts');
        $users->loadCount('posts');
        $users->loadMissing('profile');
        $this->assertTrue($users[0]->relationLoaded('posts'));
        $this->assertSame([1, 0], $users->pluck('posts_count')->all());

        $comments = Comment::with('commentable')->get();
        $comments->loadMorph('commentable', [Post::class => ['user'], Video::class => []]);
        $this->assertSame('alice', $comments->firstWhere('body', 'c')->commentable->user->name);

        // Sums are not affected by the panic.
        $post->loadSum('likers', 'likes.rating');
        $this->assertNull($post->likers_sum_likesrating);
    }

    public function testCascadingDeletes(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $post = $this->createPost($alice, 'a1');
        $alice->profile()->create(['bio' => 'x']);
        $post->tags()->attach(Tag::create(['name' => 't']));
        $bob->likedPosts()->attach($post->id);

        $alice->delete();

        // posts cascade from users, post_tag and likes cascade from posts.
        $this->assertSame(0, Post::withTrashed()->count());
        $this->assertSame(0, Profile::count());
        $this->assertSame(0, DB::table('post_tag')->count());
        $this->assertSame(0, DB::table('likes')->count());
        $this->assertSame(1, User::count());
    }

    public function testNullOnDeleteForeignKey(): void
    {
        $vn = Country::create(['name' => 'Vietnam']);
        $alice = $this->user('alice', $vn);

        $vn->delete();

        $this->assertNull($alice->fresh()->country_id);
    }

    public function testSoftDeletesThroughRelations(): void
    {
        $alice = $this->user('alice');
        $this->createPost($alice, 'kept');
        $this->createPost($alice, 'trashed')->delete();

        $this->assertSame(['kept'], $alice->posts()->pluck('title')->all());
        $this->assertSame(2, $alice->posts()->withTrashed()->count());
        $this->assertSame(['trashed'], $alice->posts()->onlyTrashed()->pluck('title')->all());
        $this->assertSame([1], User::withCount('posts')->pluck('posts_count')->all());
    }

    public function testRelationshipWritesAndIteration(): void
    {
        $alice = $this->user('alice');

        $alice->posts()->saveMany([new Post(['title' => 'a']), new Post(['title' => 'b'])]);
        $alice->posts()->firstOrCreate(['title' => 'c']);
        $alice->posts()->firstOrCreate(['title' => 'a']);
        $alice->posts()->updateOrCreate(['title' => 'b'], ['views' => 7]);

        $this->assertSame(3, $alice->posts()->count());
        $this->assertSame(7, $alice->posts()->where('title', 'b')->value('views'));

        $seen = [];
        $alice->posts()->chunkById(2, function ($posts) use (&$seen) {
            foreach ($posts as $post) {
                $seen[] = $post->title;
            }
        });
        $this->assertSame(['a', 'b', 'c'], $seen);
        $this->assertSame(['a', 'b', 'c'], $alice->posts()->lazyById(2)->pluck('title')->all());

        $this->assertSame(3, $alice->posts()->update(['views' => 1]));
        $this->assertSame(3, (int) $alice->posts()->sum('views'));
        $this->assertTrue($alice->posts()->exists());
        $this->assertFalse($this->user('bob')->posts()->exists());
    }

    public function testQueryingWithScopesOnRelations(): void
    {
        $alice = $this->user('alice');
        $this->createPost($alice, 'x', ['views' => 100]);

        $users = User::whereHas('posts', function (Builder $query) {
            $query->where('views', '>', 50)->whereNull('deleted_at');
        })->with(['posts' => fn ($q) => $q->select('id', 'user_id', 'title')])->get();

        $this->assertSame(['x'], $users[0]->posts->pluck('title')->all());
        $this->assertSame(['id', 'user_id', 'title'], array_keys($users[0]->posts[0]->getAttributes()));
    }
}
