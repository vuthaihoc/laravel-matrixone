<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MatrixOne\MatrixOneConnection;
use MatrixOne\Query\Builder;
use PDO;

class ConnectionTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('connection_items');

        parent::tearDown();
    }

    public function testTheDriverResolvesAMatrixOneConnection(): void
    {
        $connection = DB::connection();

        $this->assertInstanceOf(MatrixOneConnection::class, $connection);
        $this->assertSame('matrixone', $connection->getDriverName());
        $this->assertSame('MatrixOne', $connection->getDriverTitle());
        $this->assertInstanceOf(Builder::class, $connection->query());
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', (string) $connection->getMatrixOneVersion());
        $this->assertSame([['one' => 1]], array_map(fn ($row) => (array) $row, $connection->select('select 1 as one')));
    }

    public function testEmulatedPreparesAreEnabledByDefault(): void
    {
        $this->assertTrue((bool) DB::connection()->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES));
    }

    public function testEmulatedPreparesCanBeDisabled(): void
    {
        config(['database.connections.native' => array_merge(config('database.connections.matrixone'), ['emulate_prepares' => false])]);

        $connection = DB::connection('native');

        $this->assertFalse((bool) $connection->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        $this->assertSame(2, $connection->scalar('select ? + 1', [1]));
    }

    public function testSessionIsConfigured(): void
    {
        config(['database.connections.tz' => array_merge(config('database.connections.matrixone'), ['timezone' => '+07:00'])]);

        $this->assertSame('+07:00', DB::connection('tz')->scalar('select @@time_zone'));
    }

    public function testThreadCount(): void
    {
        $this->assertIsInt(DB::connection()->threadCount());
        $this->assertGreaterThan(0, DB::connection()->threadCount());
    }

    public function testUniqueViolationsAreReported(): void
    {
        Schema::create('connection_items', function ($table) {
            $table->id();
            $table->string('code')->unique();
        });

        DB::table('connection_items')->insert(['code' => 'a']);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('connection_items')->insert(['code' => 'a']);
    }
}
