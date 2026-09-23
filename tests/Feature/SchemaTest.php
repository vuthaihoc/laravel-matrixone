<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\Schema\Blueprint as BaseBlueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MatrixOne\Schema\Blueprint;
use RuntimeException;

class SchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllViews();
        Schema::dropAllTables();
    }

    protected function tearDown(): void
    {
        Schema::dropAllViews();
        Schema::dropAllTables();

        parent::tearDown();
    }

    public function testTheBlueprintIsTheMatrixOneBlueprint(): void
    {
        Schema::create('sc_things', function (BaseBlueprint $table) {
            $this->assertInstanceOf(Blueprint::class, $table);
            $table->id();
        });
    }

    public function testEveryCommonColumnType(): void
    {
        Schema::create('sc_types', function (Blueprint $table) {
            $table->id();
            $table->char('char', 4);
            $table->string('string', 100)->default('x')->comment('a comment');
            $table->tinyText('tiny_text')->nullable();
            $table->text('text')->nullable();
            $table->mediumText('medium_text')->nullable();
            $table->longText('long_text')->nullable();
            $table->tinyInteger('tiny_int')->default(1);
            $table->unsignedSmallInteger('small_int')->default(0);
            $table->mediumInteger('medium_int')->nullable();
            $table->integer('int')->nullable();
            $table->unsignedBigInteger('big_int')->nullable();
            $table->float('float')->nullable();
            $table->double('double')->nullable();
            $table->decimal('decimal', 10, 2)->default(0);
            $table->boolean('boolean')->default(false);
            $table->enum('enum', ['a', 'b'])->default('a');
            $table->json('json')->nullable();
            $table->jsonb('jsonb')->nullable();
            $table->date('date')->nullable();
            $table->dateTime('date_time', 6)->nullable();
            $table->dateTimeTz('date_time_tz')->nullable();
            $table->time('time')->nullable();
            $table->timestamp('timestamp')->useCurrent()->useCurrentOnUpdate();
            $table->year('year')->nullable();
            $table->binary('binary')->nullable();
            $table->uuid('uuid')->nullable();
            $table->ulid('ulid')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->macAddress('mac')->nullable();
            $table->vector('embedding', 3)->nullable();
            $table->vector64('precise', 3)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        $columns = collect(Schema::getColumns('sc_types'))->keyBy('name');

        $this->assertSame('bigint', $columns['id']['type_name']);
        $this->assertSame('bigint unsigned', $columns['id']['type']);
        $this->assertTrue($columns['id']['auto_increment']);
        $this->assertSame('varchar(100)', $columns['string']['type']);
        $this->assertSame('a comment', $columns['string']['comment']);
        $this->assertFalse($columns['string']['nullable']);
        $this->assertTrue($columns['text']['nullable']);
        $this->assertSame('decimal', $columns['decimal']['type_name']);
        $this->assertSame('json', $columns['json']['type_name']);
        $this->assertSame('smallint', $columns['year']['type_name']);
        $this->assertSame('char(36)', $columns['uuid']['type']);
        $this->assertSame('vecf32(3)', $columns['embedding']['type']);
        $this->assertSame('vecf64(3)', $columns['precise']['type']);

        foreach ($columns as $column) {
            $this->assertIsBool($column['nullable']);
            $this->assertIsBool($column['auto_increment']);
            $this->assertNull($column['generation']);
        }

        $this->assertTrue(Schema::hasColumns('sc_types', ['id', 'uuid', 'embedding']));
        $this->assertSame('vecf32', Schema::getColumnType('sc_types', 'embedding'));
    }

    public function testTablesAndSchemasExcludeSystemDatabases(): void
    {
        Schema::create('sc_a', fn (Blueprint $table) => $table->id());
        Schema::create('sc_b', fn (Blueprint $table) => $table->string('name'));

        // Like Laravel's MySQL driver, listing without a schema covers every
        // user database, so the current one is passed explicitly here.
        $this->assertSame(['sc_a', 'sc_b'], Schema::getTableListing(DB::getDatabaseName(), schemaQualified: false));
        $this->assertNotContains('mo_catalog.mo_tables', Schema::getTableListing());
        $this->assertTrue(Schema::hasTable('sc_a'));
        $this->assertFalse(Schema::hasTable('mo_tables'));

        $schemas = collect(Schema::getSchemas())->pluck('name');
        $this->assertNotContains('mo_catalog', $schemas);
        $this->assertContains(DB::getDatabaseName(), $schemas);
        $this->assertTrue(collect(Schema::getSchemas())->firstWhere('name', DB::getDatabaseName())['default']);

        // A table without a primary key gets a hidden column that must not leak.
        $this->assertSame(['name'], Schema::getColumnListing('sc_b'));
    }

    public function testIndexesAndForeignKeys(): void
    {
        Schema::create('sc_users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->index();
            $table->string('bio')->nullable();
            $table->fullText('bio');
        });

        Schema::create('sc_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('sc_users')->cascadeOnDelete();
            $table->string('title');
            $table->index(['user_id', 'title']);
        });

        $indexes = collect(Schema::getIndexes('sc_users'))->keyBy('name');

        $this->assertTrue($indexes['primary']['primary']);
        $this->assertTrue($indexes['sc_users_email_unique']['unique']);
        $this->assertFalse($indexes['sc_users_name_index']['unique']);
        $this->assertSame('btree', $indexes['sc_users_name_index']['type']);
        $this->assertSame('fulltext', $indexes['sc_users_bio_fulltext']['type']);
        $this->assertTrue(Schema::hasIndex('sc_posts', ['user_id', 'title']));

        $foreignKeys = Schema::getForeignKeys('sc_posts');
        $this->assertCount(1, $foreignKeys);
        $this->assertSame(['user_id'], $foreignKeys[0]['columns']);
        $this->assertSame('sc_users', $foreignKeys[0]['foreign_table']);
        $this->assertSame(['id'], $foreignKeys[0]['foreign_columns']);
        $this->assertSame('cascade', $foreignKeys[0]['on_delete']);

        Schema::table('sc_posts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        $this->assertSame([], Schema::getForeignKeys('sc_posts'));
    }

    public function testAlterTable(): void
    {
        Schema::create('sc_alter', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('old')->nullable();
            $table->index('name', 'sc_alter_old_name');
        });

        Schema::table('sc_alter', function (Blueprint $table) {
            $table->string('email')->nullable()->after('name')->index();
            $table->integer('score')->default(0);
            $table->string('name', 50)->nullable()->change();
            $table->renameColumn('old', 'renamed');
            $table->renameIndex('sc_alter_old_name', 'sc_alter_new_name');
            $table->comment('altered');
        });

        $this->assertSame(['id', 'name', 'email', 'renamed', 'score'], Schema::getColumnListing('sc_alter'));
        $this->assertSame('varchar(50)', collect(Schema::getColumns('sc_alter'))->firstWhere('name', 'name')['type']);
        $this->assertTrue(Schema::hasIndex('sc_alter', 'sc_alter_new_name'));
        $this->assertFalse(Schema::hasIndex('sc_alter', 'sc_alter_old_name'));
        $this->assertTrue(Schema::hasIndex('sc_alter', ['email']));
        $this->assertSame('altered', collect(Schema::getTables())->firstWhere('name', 'sc_alter')['comment']);

        Schema::table('sc_alter', function (Blueprint $table) {
            $table->dropIndex(['email']);
            $table->dropColumn(['score', 'renamed']);
        });

        $this->assertSame(['id', 'name', 'email'], Schema::getColumnListing('sc_alter'));

        Schema::rename('sc_alter', 'sc_renamed');
        $this->assertTrue(Schema::hasTable('sc_renamed'));
    }

    public function testAutoIncrementStartingValue(): void
    {
        Schema::create('sc_counter', function (Blueprint $table) {
            $table->id()->from(1000);
            $table->string('name');
        });

        $this->assertSame(1000, DB::table('sc_counter')->insertGetId(['name' => 'a']));
    }

    public function testVectorIndex(): void
    {
        Schema::create('sc_docs', function (Blueprint $table) {
            $table->id();
            $table->vector('embedding', 3);
            $table->vectorIndex('embedding')->lists(2);
        });

        $index = collect(Schema::getIndexes('sc_docs'))->firstWhere('name', 'sc_docs_embedding_vectorindex');
        $this->assertSame('ivfflat', $index['type']);
        $this->assertSame(['embedding'], $index['columns']);

        Schema::table('sc_docs', fn (Blueprint $table) => $table->dropVectorIndex(['embedding']));
        $this->assertFalse(Schema::hasIndex('sc_docs', 'sc_docs_embedding_vectorindex'));
    }

    public function testViews(): void
    {
        Schema::create('sc_base', fn (Blueprint $table) => $table->id());
        DB::statement('create view sc_view as select id from sc_base');

        $this->assertSame(['sc_view'], array_column(Schema::getViews(), 'name'));
        $this->assertTrue(Schema::hasView('sc_view'));

        Schema::dropAllViews();
        $this->assertSame([], Schema::getViews());
    }

    public function testDropAllTablesWithForeignKeys(): void
    {
        Schema::create('sc_parent', fn (Blueprint $table) => $table->id());
        Schema::create('sc_child', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sc_parent_id')->constrained('sc_parent');
        });

        Schema::dropAllTables();

        $this->assertSame([], Schema::getTableListing(DB::getDatabaseName()));
    }

    public function testUnsupportedColumnTypesThrowBeforeTouchingTheDatabase(): void
    {
        $this->expectException(RuntimeException::class);

        Schema::create('sc_set', fn (Blueprint $table) => $table->set('flags', ['a']));
    }
}
