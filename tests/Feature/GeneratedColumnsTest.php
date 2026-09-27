<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GeneratedColumnsTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('gen_items');

        parent::tearDown();
    }

    public function testStoredAndVirtualColumns(): void
    {
        Schema::dropIfExists('gen_items');
        Schema::create('gen_items', function (Blueprint $table) {
            $table->id();
            $table->integer('price');
            $table->integer('quantity');
            $table->json('meta')->nullable();
            $table->integer('total')->storedAs('price * quantity');
            $table->integer('price_with_tax')->virtualAs('price + 10');
            $table->string('source')->nullable()->storedAsJson('meta->source');
            $table->index('total');
        });

        DB::table('gen_items')->insert(['price' => 5, 'quantity' => 3, 'meta' => json_encode(['source' => 'yt'])]);
        $row = DB::table('gen_items')->first();

        $this->assertEquals(15, $row->total);
        $this->assertEquals(15, $row->price_with_tax);
        $this->assertSame('yt', $row->source);

        DB::table('gen_items')->update(['quantity' => 4]);
        $this->assertEquals(20, DB::table('gen_items')->value('total'));
        $this->assertSame(1, DB::table('gen_items')->where('total', 20)->count());
        $this->assertSame(1, DB::table('gen_items')->where('source', 'yt')->count());

        $columns = collect(Schema::getColumns('gen_items'))->keyBy('name');
        $this->assertNotNull($columns['total']['generation'] ?? null);
    }

    public function testFullTextNeedsAPrimaryKey(): void
    {
        Schema::dropIfExists('gen_items');
        Schema::create('gen_items', fn (Blueprint $table) => $table->text('body'));

        try {
            Schema::table('gen_items', fn (Blueprint $table) => $table->fullText('body'));
            $this->fail('A FULLTEXT index without a primary key must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('needs a primary key', $e->getMessage());
        }

        Schema::dropIfExists('gen_items');
        $this->expectExceptionMessage('needs a primary key');
        Schema::create('gen_items', function (Blueprint $table) {
            $table->text('body');
            $table->fullText('body');
        });
    }

    public function testFullTextWithAPrimaryKey(): void
    {
        Schema::dropIfExists('gen_items');
        Schema::create('gen_items', function (Blueprint $table) {
            $table->id();
            $table->text('body');
            $table->fullText('body');
        });
        Schema::table('gen_items', fn (Blueprint $table) => $table->text('title')->nullable());
        Schema::table('gen_items', fn (Blueprint $table) => $table->fullText('title'));

        $this->assertCount(2, collect(Schema::getIndexes('gen_items'))->where('type', 'fulltext'));
    }
}
