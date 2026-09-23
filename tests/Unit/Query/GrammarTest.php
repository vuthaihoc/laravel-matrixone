<?php

namespace MatrixOne\Tests\Unit\Query;

use InvalidArgumentException;
use MatrixOne\Tests\Unit\TestCase;
use RuntimeException;

class GrammarTest extends TestCase
{
    public function testExistsReturnsAnInteger(): void
    {
        $query = $this->query()->from('users')->where('id', 1);

        $this->assertSame(
            'select if(exists(select * from `users` where `id` = ?), 1, 0) as `exists`',
            $query->getGrammar()->compileExists($query)
        );
    }

    public function testUpsertUsesValuesFunctionInsteadOfRowAlias(): void
    {
        $query = $this->query()->from('users');

        $sql = $query->getGrammar()->compileUpsert($query, [['email' => 'a@x', 'name' => 'a']], ['email'], ['name', 'votes' => 1]);

        $this->assertSame(
            'insert into `users` (`email`, `name`) values (?, ?) on duplicate key update `name` = values(`name`), `votes` = ?',
            $sql
        );
    }

    public function testUnconditionalDeleteGetsATautologicalWhere(): void
    {
        $query = $this->query()->from('users');
        $this->assertSame('delete from `users` where 1 = 1', $query->getGrammar()->compileDelete($query));

        $query = $this->query()->from('users')->where('id', 1);
        $this->assertSame('delete from `users` where `id` = ?', $query->getGrammar()->compileDelete($query));
    }

    public function testSharedLockIsPromotedToForUpdate(): void
    {
        $this->assertSame('select * from `users` for update', $this->query()->from('users')->sharedLock()->toSql());
        $this->assertSame('select * from `users` for update', $this->query()->from('users')->lockForUpdate()->toSql());
    }

    public function testRandomOrderIgnoresTheSeed(): void
    {
        $this->assertSame('select * from `users` order by RAND()', $this->query()->from('users')->inRandomOrder(42)->toSql());
    }

    public function testRandomOrderRejectsNonNumericSeed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->query()->from('users')->inRandomOrder('x')->toSql();
    }

    public function testSavepointsAreNotSupported(): void
    {
        $this->assertFalse($this->query()->getGrammar()->supportsSavepoints());
    }

    public function testThreadCountUsesProcesslist(): void
    {
        $this->assertSame(
            'select count(*) as `Value` from information_schema.processlist',
            $this->query()->getGrammar()->compileThreadCount()
        );
    }

    public function testJsonBooleanComparesUnquotedText(): void
    {
        $this->assertSame(
            'select * from `posts` where json_unquote(json_extract(`meta`, \'$."active"\')) = \'true\'',
            $this->query()->from('posts')->where('meta->active', true)->toSql()
        );
    }

    public function testInsertGetIdUsesReturning(): void
    {
        $query = $this->query()->from('users');

        $this->assertSame(
            'insert into `users` (`name`) values (?) returning `id`',
            $query->getGrammar()->compileInsertGetId($query, ['name' => 'a'], null)
        );
        $this->assertSame(
            'insert into `users` (`name`) values (?) returning `uid`',
            $query->getGrammar()->compileInsertGetId($query, ['name' => 'a'], 'uid')
        );
    }

    public function testJsonFunctionsSupportedByMatrixOne(): void
    {
        $this->assertSame(
            'select * from `posts` where json_contains(`meta`, ?, \'$."tags"\')',
            $this->query()->from('posts')->whereJsonContains('meta->tags', 'x')->toSql()
        );
        $this->assertSame(
            'select * from `posts` where json_length(`meta`, \'$."tags"\') = ?',
            $this->query()->from('posts')->whereJsonLength('meta->tags', 2)->toSql()
        );
        $this->assertSame(
            'select * from `posts` where ifnull(json_contains_path(`meta`, \'one\', \'$."a"."b"\'), 0)',
            $this->query()->from('posts')->whereJsonContainsKey('meta->a->b')->toSql()
        );
    }

    public function testJsonUpdatesOfOneColumnShareOneJsonSet(): void
    {
        $query = $this->query()->from('docs')->where('id', 1);
        $values = [
            'name' => 'n',
            'data->flag' => true,
            'data->tags' => ['a'],
            'other' => 2,
            'data->ratio' => 1.5,
            'meta->x' => 'y',
        ];

        $this->assertSame(
            'update `docs` set `name` = ?, `data` = json_set(`data`, \'$."flag"\', true, \'$."tags"\', cast(? as json), \'$."ratio"\', cast(? as json)), '
            .'`other` = ?, `meta` = json_set(`meta`, \'$."x"\', ?) where `id` = ?',
            $query->getGrammar()->compileUpdate($query, $values)
        );
        $this->assertSame(
            ['n', '["a"]', 1.5, 2, 'y', 1],
            $query->getGrammar()->prepareBindingsForUpdate($query->getRawBindings(), $values)
        );
    }

    public function testLikeIsCaseInsensitiveUnlessBinary(): void
    {
        $this->assertSame(
            'select * from `users` where `name` ilike ? and `name` not ilike ? and `name` like binary ? and `name` not like binary ? and `name` like binary ? having `name` ilike ?',
            $this->query()->from('users')
                ->where('name', 'like', 'a%')
                ->where('name', 'not like', 'b%')
                ->whereLike('name', 'C%', caseSensitive: true)
                ->whereNotLike('name', 'D%', caseSensitive: true)
                ->where('name', 'like binary', 'E%')
                ->having('name', 'like', 'f%')
                ->toSql()
        );
        $this->assertSame('select * from `users` where `name` ilike ?', $this->query()->from('users')->whereLike('name', 'a%')->toSql());
    }

    public function testJsonOverlapsPassesTwoDocuments(): void
    {
        $this->assertSame(
            'select * from `posts` where json_overlaps(json_extract(`meta`, \'$."tags"\'), ?)',
            $this->query()->from('posts')->whereJsonOverlaps('meta->tags', ['x'])->toSql()
        );
        $this->assertSame(
            'select * from `posts` where json_overlaps(`meta`, ?)',
            $this->query()->from('posts')->whereJsonOverlaps('meta', ['a' => 1])->toSql()
        );
    }

    public function testFullTextQueryExpansionThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('query expansion');

        $this->query()->from('posts')->whereFullText('title', 'db', ['expanded' => true])->toSql();
    }

    public function testFullTextQueryExpansionIsIgnoredInBooleanModeLikeLaravel(): void
    {
        $this->assertSame(
            'select * from `posts` where match (`title`) against (? in boolean mode)',
            $this->query()->from('posts')->whereFullText('title', 'db', ['mode' => 'boolean', 'expanded' => true])->toSql()
        );
    }

    public function testFullTextRelevanceHelpers(): void
    {
        $query = $this->query()->from('posts')
            ->select('id')
            ->selectFullTextRelevance(['title', 'body'], 'db', 'score', ['mode' => 'boolean'])
            ->searchFullText('title', 'sql');

        $this->assertSame(
            'select `id`, match (`title`, `body`) against (? in boolean mode) as `score` from `posts` '
            .'where match (`title`) against (? in natural language mode) '
            .'order by match (`title`) against (? in natural language mode) desc',
            $query->toSql()
        );
        $this->assertSame(['db', 'sql', 'sql'], $query->getBindings());

        $this->assertSame(
            'select * from `posts` order by match (`title`) against (? in natural language mode) asc',
            $this->query()->from('posts')->orderByFullTextRelevance('title', 'x', direction: 'asc')->toSql()
        );
    }

    public function testLateralJoinsThrow(): void
    {
        $this->expectException(RuntimeException::class);

        $this->query()->from('users')->joinLateral($this->query()->from('posts'), 'p')->toSql();
    }

    public function testVectorDistanceHelpers(): void
    {
        $query = $this->query()->from('docs')
            ->select('id')
            ->selectVectorDistanceUsing('l2', 'embedding', [1, 2.5], 'distance')
            ->whereVectorDistanceUsing('cosine', 'embedding', [1, 2.5], '<=', 0.3)
            ->nearestTo('embedding', [1, 2.5], 5, 'inner_product');

        $this->assertSame(
            'select `id`, l2_distance(`embedding`, ?) as `distance` from `docs` where cosine_distance(`embedding`, ?) <= ? '
            .'order by inner_product(`embedding`, ?) asc limit 5',
            $query->toSql()
        );
        $this->assertSame(['[1,2.5]', '[1,2.5]', 0.3, '[1,2.5]'], $query->getBindings());
    }

    public function testUnknownVectorMetricThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->query()->from('docs')->nearestTo('embedding', [1], 5, 'hamming');
    }

    public function testLaravelVectorDistanceUsesCosine(): void
    {
        $this->assertTrue($this->query()->getGrammar()->supportsVectorDistance());
        $this->assertSame('cosine_distance(`embedding`, ?)', $this->query()->getGrammar()->compileVectorDistanceExpression('embedding'));
    }
}
