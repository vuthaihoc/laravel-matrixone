<?php

namespace MatrixOne\Tests\Unit\Schema;

use InvalidArgumentException;
use MatrixOne\Schema\Blueprint;
use MatrixOne\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class GrammarTest extends TestCase
{
    public function testAutoIncrementStartingValueIsATableOption(): void
    {
        $sql = $this->blueprintSql('users', function (Blueprint $table) {
            $table->id()->from(100);
            $table->string('name');
        }, create: true);

        $this->assertCount(1, $sql);
        $this->assertStringEndsWith(' auto_increment = 100', $sql[0]);
    }

    public function testAutoIncrementStartingValueOnExistingTableThrows(): void
    {
        $this->expectException(RuntimeException::class);

        $this->blueprintSql('users', fn (Blueprint $table) => $table->bigIncrements('big_id')->startingValue(5));
    }

    public function testRegularIndexesDropTheAlgorithm(): void
    {
        $sql = $this->blueprintSql('users', fn (Blueprint $table) => $table->index('name', 'users_name_index', 'btree'));

        $this->assertSame(['alter table `users` add index `users_name_index`(`name`)'], $sql);
    }

    public function testFullTextIndexWithParser(): void
    {
        $sql = $this->blueprintSql('posts', function (Blueprint $table) {
            $table->fullText(['title', 'body'])->parser('ngram');
        });

        $this->assertSame(['alter table `posts` add fulltext `posts_title_body_fulltext`(`title`, `body`) with parser ngram'], $sql);
    }

    public function testFullTextParserIsValidated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->blueprintSql('posts', fn (Blueprint $table) => $table->fullText('title')->parser('ngram; drop table x'));
    }

    public function testVectorColumns(): void
    {
        $sql = $this->blueprintSql('docs', function (Blueprint $table) {
            $table->vector('embedding', 768);
            $table->vector64('precise', 3)->nullable();
        });

        $this->assertSame([
            'alter table `docs` add `embedding` vecf32(768) not null',
            'alter table `docs` add `precise` vecf64(3) null',
        ], $sql);
    }

    public function testVectorColumnsRequireDimensions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->blueprintSql('docs', fn (Blueprint $table) => $table->vector('embedding'));
    }

    public function testIvfFlatVectorIndex(): void
    {
        $sql = $this->blueprintSql('docs', fn (Blueprint $table) => $table->vectorIndex('embedding')->lists(16));

        $this->assertSame(
            ["create index `docs_embedding_vectorindex` using ivfflat on `docs` (`embedding`) lists = 16 op_type 'vector_cosine_ops'"],
            $sql
        );
    }

    public function testHnswVectorIndexEnablesTheExperimentalFeature(): void
    {
        $sql = $this->blueprintSql('docs', fn (Blueprint $table) => $table->vectorIndex('embedding', 'emb_idx', 'hnsw', 'l2')->m(16)->efConstruction(64));

        $this->assertSame([
            'set experimental_hnsw_index = 1',
            "create index `emb_idx` using hnsw on `docs` (`embedding`) m = 16 ef_construction = 64 op_type 'vector_l2_ops'",
        ], $sql);
    }

    public function testDropVectorIndex(): void
    {
        $sql = $this->blueprintSql('docs', fn (Blueprint $table) => $table->dropVectorIndex(['embedding']));

        $this->assertSame(['alter table `docs` drop index `docs_embedding_vectorindex`'], $sql);
    }

    public function testYearIsStoredAsSmallint(): void
    {
        $sql = $this->blueprintSql('events', fn (Blueprint $table) => $table->year('year'));

        $this->assertSame(['alter table `events` add `year` smallint not null'], $sql);
    }

    public function testUuidIsStoredAsChar(): void
    {
        // MatrixOne's native UUID type is not readable by PHP's mysqlnd.
        $sql = $this->blueprintSql('events', fn (Blueprint $table) => $table->uuid('uuid'));

        $this->assertSame(['alter table `events` add `uuid` char(36) not null'], $sql);
    }

    /**
     * @return array<string, array{0: callable}>
     */
    public static function unsupportedColumns(): array
    {
        return [
            'set' => [fn (Blueprint $table) => $table->set('flags', ['a', 'b'])],
            'geometry' => [fn (Blueprint $table) => $table->geometry('shape')],
            'geography' => [fn (Blueprint $table) => $table->geography('shape')],
            'virtualAs' => [fn (Blueprint $table) => $table->integer('b')->virtualAs('a + 1')],
            'storedAs' => [fn (Blueprint $table) => $table->integer('b')->storedAs('a + 1')],
        ];
    }

    #[DataProvider('unsupportedColumns')]
    public function testUnsupportedColumnsThrow(callable $callback): void
    {
        $this->expectException(RuntimeException::class);

        $this->blueprintSql('things', $callback);
    }

    public function testIntrospectionQueriesExcludeHiddenColumnsAndSystemSchemas(): void
    {
        $connection = $this->connection();
        $connection->useDefaultSchemaGrammar();
        $grammar = $connection->getSchemaGrammar();

        $this->assertStringContainsString("column_name not like '\\_\\_mo\\_%'", $grammar->compileColumns(null, 'users'));
        $this->assertStringContainsString("column_name not like '\\_\\_mo\\_%'", $grammar->compileIndexes(null, 'users'));
        $this->assertStringContainsString('if(non_unique = 0, 1, 0) as `unique`', $grammar->compileIndexes(null, 'users'));
        $this->assertStringContainsString('from mo_catalog.mo_foreign_keys', $grammar->compileForeignKeys(null, 'users'));
        $this->assertStringContainsString("'mo_catalog'", $grammar->compileTables(null));
        $this->assertStringContainsString("'system_metrics'", $grammar->compileSchemas());
        $this->assertStringStartsWith('select if(exists (', $grammar->compileTableExists(null, 'users'));
    }
}
