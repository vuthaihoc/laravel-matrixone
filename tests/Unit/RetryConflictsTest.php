<?php

namespace MatrixOne\Tests\Unit;

use Illuminate\Database\QueryException;
use Illuminate\Support\Sleep;
use MatrixOne\MatrixOneConnection;
use Mockery as m;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;

class RetryConflictsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        m::close();

        parent::tearDown();
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function conflicts(): array
    {
        return [
            'w-w conflict' => [20619, 'w-w conflict'],
            'txn need retry' => [20628, 'txn need retry in rc mode'],
            'CN rolling restart' => [20634, 'retry for CN rolling restart'],
            'deadlock' => [20701, 'deadlock detected'],
            'lock wait timeout' => [20710, 'Lock wait timeout exceeded; try restarting transaction'],
        ];
    }

    #[DataProvider('conflicts')]
    public function testAStatementOutsideATransactionIsRetried(int $number, string $message): void
    {
        $statement = m::mock(PDOStatement::class);
        $statement->shouldReceive('setFetchMode', 'execute')->andReturnTrue();
        $statement->shouldReceive('fetchAll')->andReturn([(object) ['one' => 1]]);

        $pdo = m::mock(PDO::class);
        $pdo->shouldReceive('prepare')->once()->andThrow($this->matrixOneError($number, $message));
        $pdo->shouldReceive('prepare')->once()->andReturn($statement);

        $this->assertEquals([(object) ['one' => 1]], $this->connectionWith($pdo)->select('select 1 as one'));
        Sleep::assertSleptTimes(1);
    }

    public function testAStatementGivesUpAfterTheRetryAttempts(): void
    {
        $pdo = m::mock(PDO::class);
        $pdo->shouldReceive('prepare')->times(2)->andThrow($this->matrixOneError(20619, 'w-w conflict'));

        try {
            $this->connectionWith($pdo, ['retry_attempts' => 2])->select('select 1');
            $this->fail('The conflict was not thrown.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('w-w conflict', $e->getMessage());
        }

        Sleep::assertSleptTimes(1);
    }

    public function testAnUnknownCommitStatusIsNotRetried(): void
    {
        $pdo = m::mock(PDO::class);
        $pdo->shouldReceive('prepare')->once()->andThrow($this->matrixOneError(20638, 'txn commit status is unknown: timeout'));

        $this->expectException(QueryException::class);

        try {
            $this->connectionWith($pdo)->select('select 1');
        } finally {
            Sleep::assertNeverSlept();
        }
    }

    public function testOtherErrorsAreNotRetried(): void
    {
        $pdo = m::mock(PDO::class);
        $pdo->shouldReceive('prepare')->once()->andThrow($this->matrixOneError(1146, "Table 'missing' doesn't exist", '42S02'));

        $this->expectException(QueryException::class);

        try {
            $this->connectionWith($pdo)->select('select * from missing');
        } finally {
            Sleep::assertNeverSlept();
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function connectionWith(PDO $pdo, array $config = []): MatrixOneConnection
    {
        return new MatrixOneConnection($pdo, 'test', '', ['driver' => 'matrixone', 'name' => 'matrixone'] + $config);
    }

    private function matrixOneError(int $number, string $message, string $sqlState = 'HY000'): PDOException
    {
        $error = new class("SQLSTATE[{$sqlState}]: General error: {$number} {$message}") extends PDOException
        {
            public function setCode(string $code): void
            {
                $this->code = $code;
            }
        };
        $error->setCode($sqlState);
        $error->errorInfo = [$sqlState, $number, $message];

        return $error;
    }
}
