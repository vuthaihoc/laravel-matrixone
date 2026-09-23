<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\Console\DbCommand as BaseDbCommand;
use Illuminate\Support\Facades\Artisan;
use MatrixOne\Console\DbCommand;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class DbCommandTest extends TestCase
{
    public function testTheDbCommandUsesTheMysqlClientForMatrixOne(): void
    {
        $command = $this->app->make(BaseDbCommand::class);
        $this->assertInstanceOf(DbCommand::class, $command);

        $connection = config('database.connections.matrixone');

        $this->assertSame('mysql', $command->getCommand($connection));
        $this->assertSame([
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--user='.$connection['username'],
            '--password='.$connection['password'],
            '--default-character-set=utf8mb4',
            $connection['database'],
        ], $command->commandArguments($connection));

        // Other drivers keep Laravel's behaviour.
        $this->assertSame('psql', $command->getCommand(['driver' => 'pgsql']));
        $this->assertInstanceOf(DbCommand::class, Artisan::all()['db']);
    }

    public function testTheGeneratedArgumentsConnectWithTheMysqlClient(): void
    {
        if ((new ExecutableFinder)->find('mysql') === null) {
            $this->markTestSkipped('The mysql client is not installed.');
        }

        $command = $this->app->make(BaseDbCommand::class);
        $connection = config('database.connections.matrixone');

        $process = new Process([$command->getCommand($connection), ...$command->commandArguments($connection), '--execute=select 42']);
        $process->mustRun();

        $this->assertStringContainsString('42', $process->getOutput());
    }
}
