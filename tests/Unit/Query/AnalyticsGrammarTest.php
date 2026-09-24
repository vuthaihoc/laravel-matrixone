<?php

namespace MatrixOne\Tests\Unit\Query;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use MatrixOne\Tests\Unit\TestCase;

class AnalyticsGrammarTest extends TestCase
{
    public function testTimeWindow(): void
    {
        $query = $this->query()->from('metrics')
            ->select('_wstart', 'sum(v) as total')
            ->where('device', 'a')
            ->timeWindow('ts', '10 seconds', sliding: '5 second', fill: 'prev')
            ->orderBy('_wstart');

        $this->assertSame(
            'select `_wstart`, `sum(v)` as `total` from `metrics` where `device` = ? interval(`ts`, 10, second) sliding(5, second) fill(prev) order by `_wstart` asc',
            $query->toSql()
        );
    }

    public function testTimeWindowFillValue(): void
    {
        $query = $this->query()->from('metrics')->timeWindow('ts', '1 hour', fill: 'value', fillValue: 0.5);

        $this->assertStringEndsWith('interval(`ts`, 1, hour) fill(value, 0.5)', $query->toSql());
    }

    public function testTimeWindowRejectsOtherUnits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->query()->from('metrics')->timeWindow('ts', '1 week');
    }

    public function testTimeWindowRejectsAFillValueWithoutValueMode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->query()->from('metrics')->timeWindow('ts', '1 day', fill: 'prev', fillValue: 1);
    }

    public function testTimeWindowCannotBeGrouped(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->query()->from('metrics')->timeWindow('ts', '1 day')->groupBy('device')->toSql();
    }

    public function testSample(): void
    {
        $this->assertSame('select sample(*, 10 rows) from `users`', $this->query()->from('users')->sample(10)->toSql());
        $this->assertSame('select sample(*, 10 rows) from `users`', $this->query()->from('users')->select('users.*')->sample(10)->toSql());
        $this->assertSame(
            'select sample(`id`, `name`, 2.5 percent) from `users` where `active` = ?',
            $this->query()->from('users')->select('id', 'name')->where('active', 1)->samplePercent(2.5)->toSql()
        );
    }

    public function testSamplePerGroup(): void
    {
        $this->assertSame(
            'select `city`, sample(`id`, 3 rows) from `users` group by `city`',
            $this->query()->from('users')->select('city')->sample(3, 'id')->groupBy('city')->toSql()
        );
    }

    public function testSampleBounds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->query()->from('users')->samplePercent(100);
    }

    public function testTimeTravel(): void
    {
        $this->assertSame(
            "select * from `orders` {snapshot = 'daily'} where `id` = ?",
            $this->query()->from('orders')->asOfSnapshot('daily')->where('id', 1)->toSql()
        );
        $this->assertSame(
            "select * from `orders` {as of timestamp '2026-01-02 03:04:05.000000'} as `o`",
            $this->query()->from('orders as o')->asOfTimestamp(CarbonImmutable::parse('2026-01-02 03:04:05'))->toSql()
        );
        $this->assertSame(
            "select * from `orders` {snapshot = 'it''s'}",
            $this->query()->from('orders')->asOfSnapshot("it's")->toSql()
        );
        $this->assertSame(
            "select * from `orders` {snapshot = 'a\\\\'''}",
            $this->query()->from('orders')->asOfSnapshot("a\\'")->toSql()
        );
    }

    public function testTimeTravelNeedsATable(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->query()->fromSub($this->query()->from('orders'), 'o')->asOfSnapshot('daily')->toSql();
    }
}
