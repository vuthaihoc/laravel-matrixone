<?php

namespace MatrixOne\Tests\Unit\Support;

use MatrixOne\Support\FullTextQuery;
use MatrixOne\Tests\Unit\TestCase;

class FullTextQueryTest extends TestCase
{
    public function testOperators(): void
    {
        $query = FullTextQuery::make()
            ->must('machine', 'learning')
            ->mustNot('legacy')
            ->encourage('data science')
            ->discourage('beginner')
            ->phrase('neural networks')
            ->prefix('pyth');

        $this->assertSame('+machine +learning -legacy data science ~beginner "neural networks" +pyth*', (string) $query);
        $this->assertSame('"deep learning" learn*', FullTextQuery::make()->phrase('deep learning')->prefix('learn', false)->toString());
    }

    public function testUserInputCannotInjectOperators(): void
    {
        $this->assertSame('+python +legacy', FullTextQuery::make()->must('python -legacy')->toString());
        $this->assertSame('"a b"', FullTextQuery::make()->phrase('a" -b')->toString());
        $this->assertSame('how to learn python', FullTextQuery::anyOf('how to (learn) +python*')->toString());
        $this->assertTrue(FullTextQuery::anyOf(' +-~ ')->isEmpty());
    }

    public function testBuilderHelpers(): void
    {
        $query = $this->query()->from('articles')->whereFullTextQuery(['title', 'body'], FullTextQuery::make()->must('python'));

        $this->assertSame('select * from `articles` where match (`title`, `body`) against (? in boolean mode)', $query->toSql());
        $this->assertSame(['+python'], $query->getBindings());
        $this->assertSame('select * from `articles` where 0 = 1', $this->query()->from('articles')->whereFullTextQuery('title', FullTextQuery::make())->toSql());

        $search = $this->query()->from('articles')->searchFullText('title', FullTextQuery::anyOf('vector database'));
        $this->assertStringContainsString('against (? in boolean mode) desc', $search->toSql());
        $this->assertSame(['vector database', 'vector database'], $search->getBindings());
    }
}
