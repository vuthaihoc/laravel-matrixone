<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MatrixOne\Eloquent\Casts\Lowercase;

class CaseInsensitiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('ci_accounts');
        Schema::create('ci_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ci_accounts');

        parent::tearDown();
    }

    private function model(): Model
    {
        return new class extends Model
        {
            protected $table = 'ci_accounts';

            public $timestamps = false;

            protected $guarded = [];

            protected function casts(): array
            {
                return ['email' => Lowercase::class];
            }
        };
    }

    public function testIgnoreCaseHelpersMatchRegardlessOfCase(): void
    {
        DB::table('ci_accounts')->insert([
            ['email' => 'Alice@Example.com', 'name' => 'Élan'],
            ['email' => 'bob@example.com', 'name' => 'Bob'],
        ]);

        $this->assertSame(0, DB::table('ci_accounts')->where('email', 'alice@example.com')->count());
        $this->assertSame(1, DB::table('ci_accounts')->whereIgnoreCase('email', 'ALICE@example.COM')->count());
        $this->assertSame(1, DB::table('ci_accounts')->whereIgnoreCase('name', 'élan')->count());
        $this->assertSame(2, DB::table('ci_accounts')->whereInIgnoreCase('email', ['alice@EXAMPLE.com', 'BOB@example.com'])->count());
        $this->assertSame(1, DB::table('ci_accounts')->whereNotInIgnoreCase('email', ['ALICE@example.com'])->count());
        $this->assertSame(0, DB::table('ci_accounts')->whereIgnoreCase('email', 'x@y.z')->orWhereIgnoreCase('email', 'x')->count());
    }

    public function testLowercaseCastRestoresUniquenessAndIndexedLookups(): void
    {
        $model = $this->model();

        $model->newQuery()->create(['email' => 'Alice@Example.com']);
        $this->assertSame('alice@example.com', DB::table('ci_accounts')->value('email'));

        // Lookups normalize the input and keep using the unique index.
        $this->assertNotNull($model->newQuery()->where('email', Str::lower('ALICE@example.com'))->first());

        $found = $model->newQuery()->firstOrCreate(['email' => Str::lower('ALICE@EXAMPLE.COM')]);
        $this->assertSame(1, $model->newQuery()->count());
        $this->assertFalse($found->wasRecentlyCreated);

        $this->expectException(UniqueConstraintViolationException::class);
        $model->newQuery()->create(['email' => 'ALICE@example.com']);
    }
}
