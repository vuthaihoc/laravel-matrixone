<?php

namespace MatrixOne;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use MatrixOne\Connectors\MatrixOneConnector;

class MatrixOneServiceProvider extends ServiceProvider
{
    /**
     * Register the `matrixone` database driver.
     *
     * Registering a connector and a connection resolver (instead of a
     * DatabaseManager extension) lets Laravel's ConnectionFactory build the
     * connection exactly like a built-in driver, so read/write splitting,
     * sticky connections, lazy PDO creation and reconnects keep working.
     */
    public function register(): void
    {
        $this->app->bind('db.connector.matrixone', MatrixOneConnector::class);

        Connection::resolverFor('matrixone', static function ($connection, $database, $prefix, $config) {
            return new MatrixOneConnection($connection, $database, $prefix, $config);
        });
    }
}
