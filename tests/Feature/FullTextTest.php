<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MatrixOne\MatrixOneConnection;
use RuntimeException;

class FullTextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('ft_docs');
        Schema::create('ft_docs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->fullText(['title', 'body']);
            $table->fullText('meta')->parser('json');
        });

        DB::table('ft_docs')->insert([
            ['title' => 'MatrixOne database', 'body' => 'database database engine', 'meta' => json_encode(['tag' => 'red apple', 'lang' => 'en'])],
            ['title' => 'Another database', 'body' => 'vector search', 'meta' => json_encode(['tag' => 'green', 'lang' => 'vi'])],
            ['title' => 'Unrelated', 'body' => 'nothing here', 'meta' => json_encode(['tag' => 'blue'])],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ft_docs');

        parent::tearDown();
    }

    private function connection(): MatrixOneConnection
    {
        /** @var MatrixOneConnection */
        return DB::connection();
    }

    public function testFullTextIndexOnAJsonColumnWithTheJsonParser(): void
    {
        $this->assertSame(['MatrixOne database'], DB::table('ft_docs')->whereFullText('meta', 'red')->pluck('title')->all());
        $this->assertSame(['Another database'], DB::table('ft_docs')->whereFullText('meta', '+green', ['mode' => 'boolean'])->pluck('title')->all());

        $index = collect(Schema::getIndexes('ft_docs'))->firstWhere('name', 'ft_docs_meta_fulltext');
        $this->assertSame('fulltext', $index['type']);
    }

    public function testRelevanceHelpers(): void
    {
        $rows = DB::table('ft_docs')
            ->select('title')
            ->selectFullTextRelevance(['title', 'body'], 'database', 'score')
            ->searchFullText(['title', 'body'], 'database')
            ->get();

        $this->assertSame(['MatrixOne database', 'Another database'], $rows->pluck('title')->all());
        $this->assertGreaterThan((float) $rows[1]->score, (float) $rows[0]->score);
    }

    public function testQueryExpansionFailsClearly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('query expansion');

        DB::table('ft_docs')->whereFullText('title', 'database', ['expanded' => true])->get();
    }

    public function testRelevancyAlgorithmCanBeSwitchedForOneCallback(): void
    {
        $score = fn () => (float) DB::table('ft_docs')
            ->selectFullTextRelevance(['title', 'body'], 'database', 'score')
            ->orderByFullTextRelevance(['title', 'body'], 'database')
            ->value('score');

        $tfIdf = $score();

        $bm25 = $this->connection()->withSessionVariables(['ft_relevancy_algorithm' => 'BM25'], function (MatrixOneConnection $connection) use ($score) {
            $this->assertSame('BM25', $connection->getSessionVariables(['ft_relevancy_algorithm'])['ft_relevancy_algorithm']);

            return $score();
        });

        $this->assertNotEqualsWithDelta($tfIdf, $bm25, 1e-6);
        $this->assertSame('TF-IDF', $this->connection()->getSessionVariables(['ft_relevancy_algorithm'])['ft_relevancy_algorithm']);
    }

    public function testSessionVariablesFromTheConnectionConfigSurviveReconnects(): void
    {
        config(['database.connections.mo_vars' => array_merge(config('database.connections.matrixone'), [
            'variables' => ['ft_relevancy_algorithm' => 'BM25', 'fulltext_bloom_filter_pushdown' => true],
        ])]);

        /** @var MatrixOneConnection $connection */
        $connection = DB::connection('mo_vars');
        $expected = ['ft_relevancy_algorithm' => 'BM25', 'fulltext_bloom_filter_pushdown' => '1'];

        $this->assertEquals($expected, $connection->getSessionVariables(array_keys($expected)));

        $connection->reconnect();
        $this->assertEquals($expected, $connection->getSessionVariables(array_keys($expected)));
    }

    public function testExperimentalFullText2IndexNeedsItsSessionVariable(): void
    {
        $this->connection()->withSessionVariables(['experimental_fulltext2_index' => 1], function (MatrixOneConnection $connection) {
            $connection->statement('create fulltext2 index ft_docs_body_ft2 on ft_docs (body)');
        });

        $this->assertSame(['Another database'], DB::table('ft_docs')->whereFullText('body', 'vector')->pluck('title')->all());

        $this->connection()->statement('alter table ft_docs alter reindex ft_docs_body_ft2 fulltext2 force_sync');

        Schema::table('ft_docs', fn (Blueprint $table) => $table->dropIndex('ft_docs_body_ft2'));
        $this->assertFalse(Schema::hasIndex('ft_docs', 'ft_docs_body_ft2'));
    }

    public function testDropFullTextAndPrefixIndexOnText(): void
    {
        Schema::table('ft_docs', function (Blueprint $table) {
            $table->dropFullText(['title', 'body']);
            $table->rawIndex('body(100)', 'ft_docs_body_prefix');
        });

        $this->assertFalse(Schema::hasIndex('ft_docs', 'ft_docs_title_body_fulltext'));
        $this->assertTrue(Schema::hasIndex('ft_docs', 'ft_docs_body_prefix'));
    }
}
