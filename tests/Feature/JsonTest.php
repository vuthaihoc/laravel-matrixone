<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every JSON operation Laravel's MySQL grammar supports, run against a real
 * MatrixOne server.
 */
class JsonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('json_docs');
        Schema::create('json_docs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('data')->nullable();
        });

        DB::table('json_docs')->insert([
            ['name' => 'a', 'data' => json_encode([
                'str' => 'hello', 'int' => 5, 'float' => 1.5, 'bool' => true, 'off' => false, 'null' => null,
                'tags' => ['x', 'y'], 'nums' => [1, 2, 3], 'nested' => ['k' => 'v', 'deep' => ['n' => 10]],
                'dash-key' => 'dashed', 'list' => [['id' => 1], ['id' => 2]],
            ])],
            ['name' => 'b', 'data' => json_encode([
                'str' => 'World', 'int' => 20, 'float' => 2.25, 'bool' => false, 'off' => true,
                'tags' => ['z'], 'nums' => [4], 'nested' => ['k' => 'w'],
            ])],
            ['name' => 'c', 'data' => null],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('json_docs');

        parent::tearDown();
    }

    /**
     * @return string[]
     */
    private function names(callable $callback): array
    {
        return $callback(DB::table('json_docs'))->orderBy('name')->pluck('name')->all();
    }

    public function testWhereStringIntAndFloat(): void
    {
        $this->assertSame(['a'], $this->names(fn ($q) => $q->where('data->str', 'hello')));
        $this->assertSame(['a'], $this->names(fn ($q) => $q->where('data->int', 5)));
        $this->assertSame(['b'], $this->names(fn ($q) => $q->where('data->int', '>', 10)));
        $this->assertSame(['a'], $this->names(fn ($q) => $q->where('data->float', 1.5)));
        $this->assertSame(['b'], $this->names(fn ($q) => $q->where('data->str', '!=', 'hello')));
        $this->assertSame(['a'], $this->names(fn ($q) => $q->where('data->nested->k', 'v')));
        $this->assertSame(['a'], $this->names(fn ($q) => $q->where('data->nested->deep->n', 10)));
        $this->assertSame(['a'], $this->names(fn ($q) => $q->where('data->dash-key', 'dashed')));
    }

    public function testWhereBoolean(): void
    {
        $this->assertSame(['a'], $this->names(fn ($q) => $q->where('data->bool', true)));
        $this->assertSame(['b'], $this->names(fn ($q) => $q->where('data->bool', false)));
        $this->assertSame(['b'], $this->names(fn ($q) => $q->where('data->bool', '!=', true)));
    }

    public function testArrayIndexPaths(): void
    {
        $this->assertSame(['a'], $this->names(fn ($q) => $q->where('data->tags[0]', 'x')));
        $this->assertSame(['a'], $this->names(fn ($q) => $q->where('data->list[1]->id', 2)));
    }

    public function testWhereNullAndNotNull(): void
    {
        // A missing key, a JSON null and a SQL NULL document all count as null.
        $this->assertSame(['a', 'b', 'c'], $this->names(fn ($q) => $q->whereNull('data->null')));
        $this->assertSame(['b', 'c'], $this->names(fn ($q) => $q->whereNull('data->nested->deep')));
        $this->assertSame(['a', 'b'], $this->names(fn ($q) => $q->whereNotNull('data->str')));
        $this->assertSame([], $this->names(fn ($q) => $q->whereNotNull('data->null')));
    }

    public function testWhereInBetweenAndLike(): void
    {
        $this->assertSame(['a', 'b'], $this->names(fn ($q) => $q->whereIn('data->str', ['hello', 'World'])));
        $this->assertSame(['b'], $this->names(fn ($q) => $q->whereNotIn('data->str', ['hello'])));
        $this->assertSame(['a'], $this->names(fn ($q) => $q->whereBetween('data->int', [1, 10])));
        $this->assertSame(['b'], $this->names(fn ($q) => $q->whereLike('data->str', 'wor%')));
        $this->assertSame([], $this->names(fn ($q) => $q->whereLike('data->str', 'wor%', caseSensitive: true)));
        $this->assertSame(['b'], $this->names(fn ($q) => $q->whereLike('data->str', 'Wor%', caseSensitive: true)));
    }

    public function testUpdateMixesPlainColumnsAndSeveralJsonColumns(): void
    {
        DB::table('json_docs')->where('name', 'a')->update([
            'data->str' => 'x',
            'name' => 'renamed',
            'data->int' => 7,
        ]);

        $row = DB::table('json_docs')->where('name', 'renamed')->first();
        $data = json_decode((string) $row->data, true);

        $this->assertSame('x', $data['str']);
        $this->assertSame(7, $data['int']);
    }

    public function testJsonContains(): void
    {
        $this->assertSame(['a'], $this->names(fn ($q) => $q->whereJsonContains('data->tags', 'x')));
        $this->assertSame(['a'], $this->names(fn ($q) => $q->whereJsonContains('data->tags', ['x', 'y'])));
        $this->assertSame(['a'], $this->names(fn ($q) => $q->whereJsonContains('data->nums', 2)));
        $this->assertSame(['a'], $this->names(fn ($q) => $q->whereJsonContains('data->nested', ['k' => 'v'])));
        $this->assertSame(['a'], $this->names(fn ($q) => $q->whereJsonContains('data->list', [['id' => 2]])));
        $this->assertSame(['b'], $this->names(fn ($q) => $q->whereNotNull('data')->whereJsonDoesntContain('data->tags', 'x')));
        $this->assertSame(['a', 'b'], $this->names(fn ($q) => $q->whereJsonContains('data->tags', 'x')->orWhereJsonContains('data->tags', 'z')));
    }

    public function testJsonOverlaps(): void
    {
        $this->assertSame(['a', 'b'], $this->names(fn ($q) => $q->whereJsonOverlaps('data->tags', ['y', 'z'])));
        $this->assertSame(['b'], $this->names(fn ($q) => $q->whereNotNull('data')->whereJsonDoesntOverlap('data->tags', ['x'])));
    }

    public function testJsonContainsKey(): void
    {
        $this->assertSame(['a'], $this->names(fn ($q) => $q->whereJsonContainsKey('data->null')));
        $this->assertSame(['a'], $this->names(fn ($q) => $q->whereJsonContainsKey('data->nested->deep->n')));
        $this->assertSame(['a'], $this->names(fn ($q) => $q->whereJsonContainsKey('data->list[1]')));
        $this->assertSame(['b', 'c'], $this->names(fn ($q) => $q->whereJsonDoesntContainKey('data->null')));
    }

    public function testJsonLength(): void
    {
        $this->assertSame(['a'], $this->names(fn ($q) => $q->whereJsonLength('data->nums', 3)));
        $this->assertSame(['a'], $this->names(fn ($q) => $q->whereJsonLength('data->tags', '>', 1)));
        $this->assertSame(['b'], $this->names(fn ($q) => $q->whereJsonLength('data->nested', 1)));
    }

    public function testSelectPluckOrderAndGroup(): void
    {
        $this->assertSame(['hello', 'World', null], DB::table('json_docs')->orderBy('name')->pluck('data->str as str')->all());

        $row = DB::table('json_docs')->where('name', 'a')->select('data->nested->k as k', 'data->int as i', 'data->bool as b', 'data->tags as t')->first();
        $this->assertSame('v', $row->k);
        $this->assertSame('5', (string) $row->i);
        $this->assertSame('true', (string) $row->b);
        $this->assertSame(['x', 'y'], json_decode((string) $row->t, true));

        $this->assertSame(['b', 'a'], DB::table('json_docs')->whereNotNull('data')->orderByDesc('data->float')->pluck('name')->all());

        $groups = DB::table('json_docs')->whereNotNull('data')->select('data->bool as flag', DB::raw('count(*) as total'))->groupBy('data->bool')->get();
        $this->assertCount(2, $groups);
    }

    public function testUpdateJsonPaths(): void
    {
        DB::table('json_docs')->where('name', 'a')->update([
            'data->str' => 'changed',
            'data->int' => 6,
            'data->bool' => false,
            'data->null' => null,
            'data->nested->k' => 'nv',
            'data->tags' => ['p', 'q'],
            'data->newkey' => ['o' => 1],
        ]);

        $data = json_decode((string) DB::table('json_docs')->where('name', 'a')->value('data'), true);

        $this->assertSame('changed', $data['str']);
        $this->assertSame(6, $data['int']);
        $this->assertFalse($data['bool']);
        $this->assertNull($data['null']);
        $this->assertSame('nv', $data['nested']['k']);
        $this->assertSame(['p', 'q'], $data['tags']);
        $this->assertSame(['o' => 1], $data['newkey']);
    }

    public function testUpdateJsonPathWithBoolTrueAndNumbers(): void
    {
        DB::table('json_docs')->where('name', 'b')->update(['data->bool' => true, 'data->float' => 3.75]);

        $data = json_decode((string) DB::table('json_docs')->where('name', 'b')->value('data'), true);

        $this->assertTrue($data['bool']);
        $this->assertSame(3.75, $data['float']);
    }

    public function testEloquentJsonCastsAndAttributes(): void
    {
        $model = new class extends Model
        {
            protected $table = 'json_docs';

            public $timestamps = false;

            protected $guarded = [];

            protected function casts(): array
            {
                return ['data' => 'array'];
            }
        };

        $doc = $model->newQuery()->create(['name' => 'd', 'data' => ['a' => 1, 'list' => [1, 2]]]);
        $doc->update(['data->a' => 2, 'data->b' => 'new']);

        $fresh = $model->newQuery()->find($doc->getKey());
        $this->assertEquals(['a' => 2, 'list' => [1, 2], 'b' => 'new'], $fresh->data);
        $this->assertSame(1, $model->newQuery()->where('data->b', 'new')->count());

        $objectModel = new class extends Model
        {
            protected $table = 'json_docs';

            public $timestamps = false;

            protected $guarded = [];

            protected function casts(): array
            {
                return ['data' => AsArrayObject::class];
            }
        };

        $object = $objectModel->newQuery()->find($doc->getKey());
        $object->data['c'] = 3;
        $object->save();
        $this->assertSame(3, $model->newQuery()->find($doc->getKey())->data['c']);

        $collectionModel = new class extends Model
        {
            protected $table = 'json_docs';

            public $timestamps = false;

            protected $guarded = [];

            protected function casts(): array
            {
                return ['data' => AsCollection::class];
            }
        };

        $this->assertSame(2, $collectionModel->newQuery()->find($doc->getKey())->data->get('a'));
    }

    public function testUnicodeAndQuotesRoundTrip(): void
    {
        $value = ['text' => "Tiếng Việt 'quoted' \"double\" \\ emoji 🎵"];

        DB::table('json_docs')->insert(['name' => 'u', 'data' => json_encode($value)]);

        $this->assertSame($value, json_decode((string) DB::table('json_docs')->where('name', 'u')->value('data'), true));
        $this->assertSame(['u'], $this->names(fn ($q) => $q->where('data->text', $value['text'])));
    }
}
