<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MatrixOne\MatrixOneConnection;
use PDO;
use Throwable;

/**
 * Laravel's lost-connection handling on MatrixOne: a connection killed on
 * the server ("MySQL server has gone away") is re-established.
 */
class ReconnectTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('rc_items');

        parent::tearDown();
    }

    private function connectionId(): int
    {
        return (int) DB::scalar('select connection_id()');
    }

    /**
     * Kill the given MatrixOne session from another connection.
     */
    private function kill(int $id): void
    {
        $config = config('database.connections.matrixone');

        $admin = new PDO(
            sprintf('mysql:host=%s;port=%s', $config['host'], $config['port']),
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $admin->exec("kill {$id}");

        usleep(200_000);
    }

    public function testQueriesReconnectAfterTheSessionIsKilled(): void
    {
        $before = $this->connectionId();

        $this->kill($before);

        // Laravel detects the lost connection, reconnects and retries.
        $this->assertSame(1, DB::scalar('select 1'));
        $this->assertNotSame($before, $this->connectionId());
    }

    public function testALostConnectionInsideATransactionFailsAndTheNextQueryReconnects(): void
    {
        Schema::create('rc_items', fn (Blueprint $table) => $table->id());

        DB::beginTransaction();
        DB::table('rc_items')->insert([]);

        $this->kill($this->connectionId());

        try {
            DB::table('rc_items')->insert([]);
            $this->fail('A lost connection inside a transaction must not be retried silently.');
        } catch (Throwable) {
            //
        }

        try {
            DB::rollBack();
        } catch (Throwable) {
            // The rollback itself hits the lost connection.
        }

        $this->assertSame(0, DB::transactionLevel());

        // The server rolled the transaction back with the session.
        $this->assertSame(0, DB::table('rc_items')->count());
    }

    public function testSessionVariablesAreReappliedAfterReconnecting(): void
    {
        config(['database.connections.mo_rc' => array_merge(config('database.connections.matrixone'), [
            'variables' => ['ft_relevancy_algorithm' => 'BM25'],
        ])]);

        /** @var MatrixOneConnection $connection */
        $connection = DB::connection('mo_rc');
        $this->kill((int) $connection->scalar('select connection_id()'));

        $this->assertSame(['ft_relevancy_algorithm' => 'BM25'], $connection->getSessionVariables(['ft_relevancy_algorithm']));
    }
}
