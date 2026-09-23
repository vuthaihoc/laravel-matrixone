<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\EngineManager;
use Laravel\Scout\ScoutServiceProvider;
use Laravel\Scout\Searchable;
use MatrixOne\Eloquent\Casts\AsVector;
use MatrixOne\MatrixOneServiceProvider;
use MatrixOne\Scout\MatrixOneEngine;

class ScoutArticle extends Model
{
    use Searchable;

    protected $table = 'scout_articles';

    public $timestamps = false;

    protected $guarded = [];

    /** Embeddings by topic, so the tests need no AI provider. */
    public const TOPICS = [
        'databases' => [1.0, 0.0, 0.0],
        'music' => [0.0, 1.0, 0.0],
        'mixed' => [0.7, 0.7, 0.0],
    ];

    protected function casts(): array
    {
        return ['embedding' => AsVector::class];
    }

    /**
     * @return array<string, mixed>
     */
    #[SearchUsingFullText(['title', 'body'])]
    #[SearchUsingPrefix(['sku'])]
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'sku' => $this->sku,
        ];
    }

    /**
     * @return array<int, float>
     */
    public function toSearchableEmbedding(): array
    {
        return self::TOPICS[$this->topic];
    }
}

class ScoutPlainArticle extends ScoutArticle
{
    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'sku' => $this->sku];
    }
}

class ScoutBooleanArticle extends ScoutArticle
{
    /**
     * @return array<string, mixed>
     */
    #[SearchUsingFullText(['title', 'body'], ['mode' => 'boolean'])]
    public function toSearchableArray(): array
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}

/**
 * The MatrixOne Scout engine with deterministic query embeddings.
 */
class FakeEmbeddingsEngine extends MatrixOneEngine
{
    protected function generateEmbeddings(array $inputs): array
    {
        return array_map(fn ($input) => ScoutArticle::TOPICS[$input] ?? [0.0, 0.0, 1.0], array_values($inputs));
    }
}

class ScoutTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MatrixOneServiceProvider::class, ScoutServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('scout.driver', 'matrixone');
        $app['config']->set('scout.queue', false);
        $app['config']->set('scout.after_commit', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('scout_articles');
        // No foreign keys: the table has a FULLTEXT index (MatrixOne 4.2.4).
        Schema::create('scout_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('sku');
            $table->string('topic');
            $table->string('status')->default('published');
            $table->vector('embedding', 3)->nullable();
            $table->fullText(['title', 'body']);
        });

        $this->app->make(EngineManager::class)->extend('matrixone', fn () => new FakeEmbeddingsEngine);

        ScoutArticle::create(['title' => 'MatrixOne basics', 'body' => 'a database for database people', 'sku' => 'DB-100', 'topic' => 'databases']);
        ScoutArticle::create(['title' => 'Guitar chords', 'body' => 'songs and music theory', 'sku' => 'MU-200', 'topic' => 'music']);
        ScoutArticle::create(['title' => 'Playlists in SQL', 'body' => 'store songs in a database', 'sku' => 'DB-300', 'topic' => 'mixed', 'status' => 'draft']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('scout_articles');

        parent::tearDown();
    }

    public function testTheMatrixOneEngineIsRegistered(): void
    {
        $this->assertInstanceOf(MatrixOneEngine::class, $this->app->make(EngineManager::class)->engine('matrixone'));
    }

    public function testSavingAModelStoresItsEmbedding(): void
    {
        $this->assertSame([1.0, 0.0, 0.0], ScoutArticle::where('sku', 'DB-100')->first()->embedding);
        $this->assertSame(3, DB::table('scout_articles')->whereNotNull('embedding')->count());
    }

    public function testFullTextPrefixAndConstraints(): void
    {
        $this->assertEqualsCanonicalizing(['DB-100', 'DB-300'], ScoutArticle::search('database')->get()->pluck('sku')->all());
        $this->assertSame(['MU-200'], ScoutArticle::search('MU-2')->get()->pluck('sku')->all());
        $this->assertSame(['DB-100'], ScoutArticle::search('database')->where('status', 'published')->get()->pluck('sku')->all());
        $this->assertSame(['DB-300'], ScoutArticle::search('database')->whereIn('status', ['draft'])->get()->pluck('sku')->all());
        $this->assertSame(['DB-100'], ScoutArticle::search('database')->whereNotIn('status', ['draft'])->get()->pluck('sku')->all());

        $page = ScoutArticle::search('database')->paginate(1);
        $this->assertSame(2, $page->total());
        $this->assertCount(1, $page->items());

        $this->assertSame(['DB-100'], ScoutArticle::search('database')->query(fn ($query) => $query->where('topic', 'databases'))->get()->pluck('sku')->all());
    }

    public function testFullTextResultsAreOrderedByRelevance(): void
    {
        // "database" appears twice in DB-100 and once in DB-300.
        $this->assertSame(['DB-100', 'DB-300'], ScoutArticle::search('database')->get()->pluck('sku')->all());
    }

    public function testMultiWordSearchesMatchAnyWord(): void
    {
        // Like MySQL's natural language mode: any of the words, by relevance.
        $this->assertSame(['MU-200'], ScoutArticle::search('guitar chords')->get()->pluck('sku')->all());
        $this->assertEqualsCanonicalizing(['MU-200', 'DB-300', 'DB-100'], ScoutArticle::search('songs database')->get()->pluck('sku')->all());
        $this->assertSame([], ScoutArticle::search('+++')->get()->pluck('sku')->all());
    }

    public function testBooleanModeModelsKeepOperators(): void
    {
        $this->assertSame(['MU-200'], ScoutBooleanArticle::search('+songs -database')->get()->pluck('sku')->all());
        $this->assertSame(['DB-300'], ScoutBooleanArticle::search('+songs +database')->get()->pluck('sku')->all());
    }

    public function testTheBuiltInDatabaseEngineWorksWithoutFullTextColumns(): void
    {
        config(['scout.driver' => 'database']);

        // LIKE-only models work with Scout's own engine (case-insensitively).
        $this->assertSame(['MU-200'], ScoutPlainArticle::search('mu-2')->get()->pluck('sku')->all());
        $this->assertSame(['DB-300', 'DB-100'], ScoutPlainArticle::search('db')->get()->pluck('sku')->all());
    }

    public function testTheBuiltInDatabaseEngineCannotMixFullTextAndLikeColumns(): void
    {
        config(['scout.driver' => 'database']);

        // MatrixOne cannot OR a MATCH ... AGAINST with other predicates;
        // SCOUT_DRIVER=matrixone rewrites the query.
        $this->expectException(QueryException::class);

        ScoutArticle::search('database')->get();
    }

    public function testSemanticSearch(): void
    {
        $this->assertSame(['DB-100', 'DB-300'], ScoutArticle::search('databases')->semantic(0.5)->get()->pluck('sku')->all());
        $this->assertSame(['MU-200', 'DB-300'], ScoutArticle::search('music')->semantic(0.5)->get()->pluck('sku')->all());
        $this->assertSame(['DB-100'], ScoutArticle::search('databases')->semantic(0.9)->get()->pluck('sku')->all());
    }

    public function testHybridSearch(): void
    {
        // Text matches "songs" (MU-200, DB-300); vectors close to "music" (MU-200, DB-300).
        $results = ScoutArticle::search('songs')->hybrid()->get()->pluck('sku')->all();

        $this->assertSame('MU-200', $results[0] ?? null);
        $this->assertContains('DB-300', $results);
        $this->assertNotContains('DB-100', $results);
    }
}
