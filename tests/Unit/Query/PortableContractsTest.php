<?php

namespace MatrixOne\Tests\Unit\Query;

use Carbon\CarbonImmutable;
use DbPortable\Contracts\HistoricalReads;
use DbPortable\Contracts\SearchBox;
use MatrixOne\Tests\Unit\TestCase;

/**
 * The laravel-db-portable contracts the builder implements.
 */
class PortableContractsTest extends TestCase
{
    public function testTheBuilderImplementsTheContracts(): void
    {
        $this->assertInstanceOf(HistoricalReads::class, $this->query());
        $this->assertInstanceOf(SearchBox::class, $this->query());
    }

    public function testHistoricalReads(): void
    {
        // Reads do not contend with writes: readStale() reads current data.
        $this->assertSame('select * from `orders`', $this->query()->from('orders')->readStale()->toSql());

        $this->assertSame(
            "select * from `orders` {as of timestamp '2026-01-02 10:04:05.000000'}",
            $this->connection(['timezone' => '+07:00'])->query()->from('orders')
                ->asOfTime(CarbonImmutable::parse('2026-01-02 03:04:05', 'UTC'))->toSql()
        );
        $this->assertMatchesRegularExpression(
            "/^select \\* from `orders` \\{as of timestamp '\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}\\.\\d{6}'\\}$/",
            $this->query()->from('orders')->asOfTime('-10s')->toSql()
        );
        $this->assertSame('select * from `orders`', $this->query()->from('orders')->asOfTime('-10s')->readCurrent()->toSql());
        $this->assertSame('select * from `orders`', $this->query()->from('orders')->asOfSnapshot('daily')->withoutTimeTravel()->toSql());
    }
}
