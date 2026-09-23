<?php

namespace MatrixOne\Tests\Unit\Query;

use InvalidArgumentException;
use MatrixOne\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testJsonContainsKeyUsesJsonExtract(): void
    {
        $this->assertSame(
            'select * from `posts` where json_extract(`meta`, \'$."a"."b"\') is not null',
            $this->query()->from('posts')->whereJsonContainsKey('meta->a->b')->toSql()
        );
    }

    public function testJsonBooleanComparesUnquotedText(): void
    {
        $this->assertSame(
            'select * from `posts` where json_unquote(json_extract(`meta`, \'$."active"\')) = \'true\'',
            $this->query()->from('posts')->where('meta->active', true)->toSql()
        );
    }

    /**
     * @return array<string, array{0: callable}>
     */
    public static function unsupportedJsonOperations(): array
    {
        return [
            'contains' => [fn ($query) => $query->whereJsonContains('meta->tags', 'x')],
            'overlaps' => [fn ($query) => $query->whereJsonOverlaps('meta->tags', ['x'])],
            'length' => [fn ($query) => $query->whereJsonLength('meta->tags', 2)],
        ];
    }

    #[DataProvider('unsupportedJsonOperations')]
    public function testUnsupportedJsonOperationsThrow(callable $callback): void
    {
        $this->expectException(RuntimeException::class);

        $callback($this->query()->from('posts'))->toSql();
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
