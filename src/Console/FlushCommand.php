<?php

namespace MatrixOne\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use MatrixOne\MatrixOneConnection;
use RuntimeException;

class FlushCommand extends Command
{
    /** @var string */
    protected $signature = 'matrixone:flush
        {tables?* : Tables to write to object storage}
        {--all : Every table of the connection\'s database}
        {--checkpoint : Run a checkpoint (every table of every database)}
        {--global : With --checkpoint, run a global checkpoint}
        {--database= : The database connection to use (an administrative user is required)}';

    /** @var string */
    protected $description = 'Write recent MatrixOne writes from memory to object storage (S3), per table or with a checkpoint';

    public function handle(ConnectionResolverInterface $db): int
    {
        $connection = $db->connection(is_string($this->option('database')) ? $this->option('database') : null);

        if (! $connection instanceof MatrixOneConnection) {
            throw new RuntimeException('The matrixone:flush command needs a matrixone connection.');
        }

        $tables = array_values(array_filter((array) $this->argument('tables'), 'is_string'));
        $all = (bool) $this->option('all');
        $checkpoint = (bool) $this->option('checkpoint');

        if ($tables === [] && ! $all && ! $checkpoint) {
            $this->components->error('Name the tables to flush, or use --all or --checkpoint.');

            return self::FAILURE;
        }

        try {
            if ($checkpoint) {
                $start = microtime(true);
                $connection->checkpoint((bool) $this->option('global'));
                $this->components->twoColumnDetail(
                    $this->option('global') ? 'global checkpoint' : 'checkpoint',
                    round(microtime(true) - $start, 2).'s'
                );
            }

            if ($tables !== [] || $all) {
                $connection->flushTables(
                    $all ? null : $tables,
                    fn (string $table, float $seconds) => $this->components->twoColumnDetail($table, round($seconds, 2).'s'),
                );
            }
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
