<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SuggestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('suggest_words');
        Schema::create('suggest_words', function (Blueprint $table) {
            $table->id();
            $table->string('word');
        });

        DB::table('suggest_words')->insert(array_map(fn ($word) => ['word' => $word], [
            'apple', 'Application', 'apply', 'pineapple', 'Xin chào', '50% off', '50 of', 'a_b', 'axb',
        ]));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('suggest_words');

        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function words(callable $callback): array
    {
        return $callback(DB::table('suggest_words'))->pluck('word')->all();
    }

    public function testStartsWithAndContainsIgnoreCaseAndEscapeWildcards(): void
    {
        $this->assertSame(['apple', 'Application', 'apply'], $this->words(fn ($q) => $q->whereStartsWith('word', 'APP')->orderBy('id')));
        $this->assertSame(['50% off'], $this->words(fn ($q) => $q->whereContains('word', '0% o')));
        $this->assertSame(['a_b'], $this->words(fn ($q) => $q->whereStartsWith('word', 'a_')));
        $this->assertSame(['apple', 'pineapple'], $this->words(fn ($q) => $q->whereContains('word', 'pple')->orderBy('id')));
    }

    public function testSuggestPutsPrefixMatchesFirst(): void
    {
        $this->assertSame(['apple', 'apply', 'pineapple'], $this->words(fn ($q) => $q->suggest('word', 'appl')->where('word', '!=', 'Application')));
        $this->assertSame(['apple', 'apply', 'Application', 'pineapple'], $this->words(fn ($q) => $q->suggest('word', 'app')));
        $this->assertSame(['apple', 'pineapple'], $this->words(fn ($q) => $q->suggest('word', 'pple')));
        $this->assertSame(['a_b', 'apple', 'apply', 'Application'], $this->words(fn ($q) => $q->suggest('word', 'a')->where('word', 'not like', 'ax%')));
        $this->assertSame([], $this->words(fn ($q) => $q->suggest('word', '  ')));
    }
}
