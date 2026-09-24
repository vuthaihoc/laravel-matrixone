<?php

namespace MatrixOne\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use MatrixOne\MatrixOneConnection;
use RuntimeException;

class PitrCommand extends Command
{
    /** @var string */
    protected $signature = 'matrixone:pitr
        {action=list : create, alter, drop or list}
        {name? : The PITR name}
        {--range=1d : Retention: a number followed by h, d, mo or y}
        {--table= : Cover one table instead of the database}
        {--database= : The database connection to use}';

    /** @var string */
    protected $description = 'Create, alter, drop or list MatrixOne point-in-time recovery (PITR) ranges';

    public function handle(ConnectionResolverInterface $db): int
    {
        $connection = $db->connection(is_string($this->option('database')) ? $this->option('database') : null);

        if (! $connection instanceof MatrixOneConnection) {
            throw new RuntimeException('The matrixone:pitr command needs a matrixone connection.');
        }

        $action = $this->argument('action');
        $action = is_string($action) ? $action : 'list';
        $name = $this->argument('name');

        if ($action === 'list') {
            $this->table(
                ['Name', 'Level', 'Database', 'Table', 'Range', 'Modified'],
                array_map(fn ($row) => [
                    $row['pitr_name'], $row['pitr_level'], $row['database_name'], $row['table_name'],
                    $row['pitr_length'].$row['pitr_unit'], $row['modified_time'],
                ], $connection->getPitrs()),
            );

            return self::SUCCESS;
        }

        if (! is_string($name) || $name === '') {
            $this->components->error('A PITR name is required.');

            return self::FAILURE;
        }

        $option = $this->option('range');

        if (! is_string($option) || ! preg_match('/^(\d+)(h|d|mo|y)$/', $option, $range)) {
            $this->components->error('The range must be a number followed by h, d, mo or y (e.g. 7d).');

            return self::FAILURE;
        }

        [, $length, $unit] = $range;

        $table = $this->option('table');

        match ($action) {
            'create' => $connection->createPitr($name, (int) $length, $unit, is_string($table) ? $table : null),
            'alter' => $connection->alterPitr($name, (int) $length, $unit),
            'drop' => $connection->dropPitr($name),
            default => throw new RuntimeException("Unknown action [{$action}]: use create, alter, drop or list."),
        };

        $this->components->info("PITR [{$name}] {$action}".($action === 'drop' ? 'ped' : 'ed').'.');

        return self::SUCCESS;
    }
}
