<?php

namespace MatrixOne\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use MatrixOne\MatrixOneConnection;
use RuntimeException;

class SnapshotCommand extends Command
{
    /** @var string */
    protected $signature = 'matrixone:snapshot
        {action=list : create, drop or list}
        {name? : The snapshot name}
        {--table= : Snapshot one table instead of the database}
        {--account : Snapshot the whole account}
        {--database= : The database connection to use}';

    /** @var string */
    protected $description = 'Create, drop or list MatrixOne snapshots';

    public function handle(ConnectionResolverInterface $db): int
    {
        $connection = $db->connection(is_string($this->option('database')) ? $this->option('database') : null);

        if (! $connection instanceof MatrixOneConnection) {
            throw new RuntimeException('The matrixone:snapshot command needs a matrixone connection.');
        }

        $action = $this->argument('action');
        $action = is_string($action) ? $action : 'list';
        $name = $this->argument('name');

        if ($action === 'list') {
            $this->table(
                ['Name', 'Timestamp', 'Level', 'Database', 'Table'],
                array_map(fn ($row) => [
                    $row['snapshot_name'], $row['timestamp'], $row['snapshot_level'], $row['database_name'], $row['table_name'],
                ], $connection->getSnapshots()),
            );

            return self::SUCCESS;
        }

        if (! is_string($name) || $name === '') {
            $this->components->error('A snapshot name is required.');

            return self::FAILURE;
        }

        $table = $this->option('table');

        match ($action) {
            'create' => $this->option('account')
                ? $connection->createAccountSnapshot($name)
                : $connection->createSnapshot($name, is_string($table) ? $table : null),
            'drop' => $connection->dropSnapshot($name),
            default => throw new RuntimeException("Unknown action [{$action}]: use create, drop or list."),
        };

        $this->components->info("Snapshot [{$name}] ".($action === 'create' ? 'created' : 'dropped').'.');

        return self::SUCCESS;
    }
}
