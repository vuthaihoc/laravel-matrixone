<?php

namespace MatrixOne\Console;

use Illuminate\Database\Console\DbCommand as BaseDbCommand;

/**
 * `php artisan db` for MatrixOne connections, using the MySQL client
 * (MatrixOne speaks the MySQL protocol).
 */
class DbCommand extends BaseDbCommand
{
    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $connection
     */
    public function getCommand(array $connection)
    {
        if (($connection['driver'] ?? null) === 'matrixone') {
            return 'mysql';
        }

        return parent::getCommand($connection);
    }

    /**
     * Get the arguments for the MySQL CLI connecting to MatrixOne.
     *
     * @param  array<string, mixed>  $connection
     * @return array<int, string>
     */
    protected function getMatrixoneArguments(array $connection): array
    {
        /** @var array<int, string> */
        return $this->getMysqlArguments($connection);
    }
}
