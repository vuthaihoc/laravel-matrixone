<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Scout\EngineManager;
use Laravel\Scout\ScoutServiceProvider;
use Laravel\Scout\Searchable;
use MatrixOne\MatrixOneServiceProvider;
use MatrixOne\Scout\MatrixOneIndexEngine;

/**
 * A model living in SQLite, indexed in MatrixOne.
 */
class IndexedArticle extends Model
{
    use Searchable, SoftDeletes;

    protected $connection = 'sqlite';

    protected $table = 'indexed_articles';

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'published', 'views' => 0, 'topic' => 'databases'];

    /** Embeddings by topic, so the tests need no AI provider. */
    public const TOPICS = [
        'databases' => [1.0, 0.0, 0.0],
        'music' => [0.0, 1.0, 0.0],
        'mixed' => [0.7, 0.7, 0.0],
    ];

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
            'views' => $this->views,
        ];
    }

    public function toSearchableEmbedding(): string
    {
        return (string) $this->topic;
    }
}

/**
 * The index engine with deterministic embeddings.
 */
class FakeIndexEngine extends MatrixOneIndexEngine
{
    protected function generateEmbeddings(array $inputs): array
    {
        return array_map(fn ($input) => IndexedArticle::TOPICS[$input] ?? [0.0, 0.0, 1.0], $inputs);
    }
}

class ScoutIndexTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MatrixOneServiceProvider::class, ScoutServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The application database is SQLite; MatrixOne only holds the index.
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('scout.driver', 'matrixone-index');
        $app['config']->set('scout.queue', false);
        $app['config']->set('scout.after_commit', false);
        $app['config']->set('scout.soft_delete', true);
        $app['config']->set('scout.matrixone-index', [
            'connection' => 'matrixone',
            'index-settings' => [
                IndexedArticle::class => [
                    'fulltext' => ['title', 'body'],
                    'filterable' => ['status'],
                    'sortable' => ['views' => 'integer'],
                    'fold_accents' => true,
                    'embedding' => 3,
                ],
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('matrixone')->dropIfExists('indexed_articles');

        Schema::connection('sqlite')->create('indexed_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('status')->default('published');
            $table->integer('views')->default(0);
            $table->string('topic')->default('databases');
            $table->timestamps();
            $table->softDeletes();
        });

        $this->app->make(EngineManager::class)->extend('matrixone-index', fn ($app) => new FakeIndexEngine(
            $app['db'],
            (array) $app['config']->get('scout.matrixone-index')
        ));
        $this->app->make(EngineManager::class)->forgetEngines();
    }

    protected function tearDown(): void
    {
        Schema::connection('matrixone')->dropIfExists('indexed_articles');

        parent::tearDown();
    }

    private function article(string $title, string $body = '', array $attributes = []): IndexedArticle
    {
        return IndexedArticle::create(['title' => $title, 'body' => $body] + $attributes);
    }

    public function testTheDriverIsRegistered(): void
    {
        $this->assertInstanceOf(MatrixOneIndexEngine::class, $this->app->make(EngineManager::class)->engine('matrixone-index'));
    }

    public function testItIndexesModelsOfAnotherDatabaseInMatrixone(): void
    {
        $article = $this->article('MatrixOne full-text search', 'Indexes live in a separate database');

        $this->assertTrue(Schema::connection('matrixone')->hasTable('indexed_articles'));
        $this->assertSame(1, $this->app['db']->connection('matrixone')->table('indexed_articles')->count());

        $found = IndexedArticle::search('separate')->get();

        $this->assertSame([$article->id], $found->pluck('id')->all());
        $this->assertSame('sqlite', $found->first()?->getConnectionName());
    }

    public function testAnyWordMatchesAreRankedByRelevance(): void
    {
        $one = $this->article('Database tuning');
        $both = $this->article('Database search engines', 'search and database');
        $this->article('Cooking pasta');

        $ids = IndexedArticle::search('database search')->get()->pluck('id')->all();

        $this->assertSame($both->id, $ids[0]);
        $this->assertEqualsCanonicalizing([$one->id, $both->id], $ids);
    }

    public function testAccentsAreFolded(): void
    {
        $article = $this->article('Học tiếng Việt ở Đà Nẵng');

        $this->assertSame([$article->id], IndexedArticle::search('tieng viet da nang')->get()->pluck('id')->all());
        $this->assertSame([$article->id], IndexedArticle::search('Tiếng Việt')->get()->pluck('id')->all());
    }

    public function testPrefixMatchingIsOptIn(): void
    {
        $article = $this->article('Learning databases');

        $this->assertCount(0, IndexedArticle::search('learn')->get());

        $this->app['config']->set('scout.matrixone-index.index-settings.'.IndexedArticle::class.'.prefix', true);
        $this->app->make(EngineManager::class)->forgetEngines();

        $this->assertSame([$article->id], IndexedArticle::search('learn')->get()->pluck('id')->all());
    }

    public function testFiltersAndSorting(): void
    {
        $low = $this->article('Database one', '', ['views' => 5]);
        $high = $this->article('Database two', '', ['views' => 50]);
        $this->article('Database draft', '', ['status' => 'draft', 'views' => 100]);

        $this->assertSame(
            [$high->id, $low->id],
            IndexedArticle::search('database')->where('status', 'published')->orderBy('views', 'desc')->get()->pluck('id')->all()
        );
        $this->assertSame(
            [$high->id],
            IndexedArticle::search('database')->whereIn('status', ['published'])->where('views', '>', 10)->get()->pluck('id')->all()
        );
        $this->assertCount(2, IndexedArticle::search('')->whereNotIn('status', ['draft'])->get());
    }

    public function testUnknownFiltersAreRejected(): void
    {
        $this->article('Database');

        $this->expectException(InvalidArgumentException::class);

        IndexedArticle::search('database')->where('title', 'Database')->get();
    }

    public function testPaginationReportsTheTotal(): void
    {
        foreach (range(1, 5) as $i) {
            $this->article("Database {$i}", '', ['views' => $i]);
        }

        $page = IndexedArticle::search('database')->orderBy('views')->paginate(2, 'page', 2);

        $this->assertSame(5, $page->total());
        $this->assertSame([3, 4], $page->getCollection()->pluck('views')->all());
    }

    public function testUpdatesDeletesAndUnsearchable(): void
    {
        $article = $this->article('Old title');
        $article->update(['title' => 'New title']);

        $this->assertCount(0, IndexedArticle::search('old')->get());
        $this->assertCount(1, IndexedArticle::search('new')->get());

        $article->unsearchable();
        $this->assertCount(0, IndexedArticle::search('new')->get());
    }

    public function testSoftDeletedModels(): void
    {
        $kept = $this->article('Database kept');
        $trashed = $this->article('Database trashed');
        $trashed->delete();

        $this->assertSame([$kept->id], IndexedArticle::search('database')->get()->pluck('id')->all());
        $this->assertCount(2, IndexedArticle::search('database')->withTrashed()->get());
        $this->assertSame([$trashed->id], IndexedArticle::search('database')->onlyTrashed()->get()->pluck('id')->all());
    }

    public function testFlushAndIndexCommands(): void
    {
        $this->article('Database');

        IndexedArticle::removeAllFromSearch();
        $this->assertCount(0, IndexedArticle::search('database')->get());

        IndexedArticle::makeAllSearchable();
        $this->assertCount(1, IndexedArticle::search('database')->get());

        $this->artisan('scout:delete-index', ['name' => 'indexed_articles'])->assertSuccessful();
        $this->assertFalse(Schema::connection('matrixone')->hasTable('indexed_articles'));
        $this->assertCount(0, IndexedArticle::search('database')->get());

        $this->artisan('scout:index', ['name' => 'indexed_articles'])->assertSuccessful();
        $this->assertTrue(Schema::connection('matrixone')->hasTable('indexed_articles'));
    }

    public function testBooleanModeKeepsOperators(): void
    {
        $this->app['config']->set('scout.matrixone-index.index-settings.'.IndexedArticle::class.'.mode', 'boolean');
        $this->app->make(EngineManager::class)->forgetEngines();

        $this->article('Database search');
        $only = $this->article('Database tuning');

        $this->assertSame([$only->id], IndexedArticle::search('+database -search')->get()->pluck('id')->all());
    }

    public function testSemanticAndHybridSearch(): void
    {
        $databases = $this->article('Storage engines', '', ['topic' => 'databases']);
        $music = $this->article('Guitar chords', '', ['topic' => 'music']);
        $mixed = $this->article('Guitar database', '', ['topic' => 'mixed']);

        $this->assertSame(
            [$databases->id, $mixed->id],
            IndexedArticle::search('databases')->semantic()->get()->pluck('id')->all()
        );

        $hybrid = IndexedArticle::search('guitar')->hybrid()->get()->pluck('id')->all();
        $this->assertSame($mixed->id, $hybrid[0]);
        $this->assertContains($music->id, $hybrid);
    }
}
