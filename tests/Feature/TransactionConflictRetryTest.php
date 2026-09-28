<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use RuntimeException;

class TransactionConflictRetryTest extends TestCase
{
    /** "committed" or "failed": how the other session's transaction ended. */
    private string $otherSessionResult = '';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('conflict_counters');
        Schema::create('conflict_counters', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('value');
        });
        DB::table('conflict_counters')->insert([['id' => 1, 'value' => 0], ['id' => 2, 'value' => 0]]);

        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        Schema::dropIfExists('conflict_counters');

        parent::tearDown();
    }

    public function testADeadlockedTransactionIsRetried(): void
    {
        $attempts = $this->incrementBothInDeadlock(fn ($callback) => DB::transaction($callback));

        $this->assertSame(2, $attempts);
        // The retry applied its increments once, whatever the other session did.
        $this->assertSame([1, 1], $this->valuesWithoutOtherSession());
        Sleep::assertSleptTimes(1);
    }

    public function testAnExplicitAttemptCountIsKept(): void
    {
        $attempts = 0;

        try {
            $this->incrementBothInDeadlock(fn ($callback) => DB::transaction($callback, 1), $attempts);
            $this->fail('The deadlock was not thrown.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('deadlock detected', $e->getMessage());
        }

        $this->assertSame(1, $attempts);
        Sleep::assertNeverSlept();
    }

    /**
     * Lock row 1, let another session lock row 2 then wait for row 1, and lock row 2: MatrixOne
     * detects the deadlock on the first attempt.
     *
     * @param  callable(\Closure): mixed  $transaction
     */
    private function incrementBothInDeadlock(callable $transaction, int &$attempts = 0): int
    {
        [$process, $pipes] = $this->startOtherSession();

        try {
            $transaction(function () use (&$attempts, $pipes) {
                $attempts++;
                DB::table('conflict_counters')->where('id', 1)->increment('value');

                if ($attempts === 1) {
                    fwrite($pipes[0], "go\n");
                    usleep(500_000);   // the other session now waits for row 1
                }

                DB::table('conflict_counters')->where('id', 2)->increment('value');
            });
        } finally {
            fclose($pipes[0]);
            $this->otherSessionResult = trim((string) stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            proc_close($process);
        }

        return $attempts;
    }

    /**
     * @return list<int>
     */
    private function valuesWithoutOtherSession(): array
    {
        $values = DB::table('conflict_counters')->orderBy('id')->pluck('value')->map(fn ($value) => (int) $value)->all();
        $other = $this->otherSessionResult === 'committed' ? 100 : 0;

        return [$values[0] - $other, $values[1] - $other];
    }

    /**
     * A PHP process with its own session: it locks row 2, waits for "go", then updates row 1.
     *
     * @return array{resource, array<int, resource>}
     */
    private function startOtherSession(): array
    {
        $config = DB::connection()->getConfig();
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);
        $script = <<<'PHP'
            [$dsn, $user, $password] = array_slice($argv, 1);
            $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->beginTransaction();
            $pdo->exec('update conflict_counters set value = value + 100 where id = 2');
            echo "locked\n";
            fgets(STDIN);
            try {
                $pdo->exec('update conflict_counters set value = value + 100 where id = 1');
                $pdo->commit();
                echo 'committed';
            } catch (PDOException $e) {
                echo 'failed';
            }
            PHP;

        $process = proc_open(
            [PHP_BINARY, '-r', $script, $dsn, (string) $config['username'], (string) $config['password']],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process) || fgets($pipes[1]) !== "locked\n") {
            throw new RuntimeException('The other session did not start.');
        }

        return [$process, $pipes];
    }
}
