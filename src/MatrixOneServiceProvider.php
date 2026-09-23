<?php

namespace MatrixOne;

use Illuminate\Database\Connection;
use Illuminate\Database\Console\DbCommand as BaseDbCommand;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\ServiceProvider;
use MatrixOne\Connectors\MatrixOneConnector;
use MatrixOne\Console\DbCommand;

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

        static::registerBlueprintMacros();

        // `php artisan db` only knows Laravel's built-in drivers.
        $this->app->extend(BaseDbCommand::class, fn ($command, $app) => $app->make(DbCommand::class));
    }

    /**
     * Register the MatrixOne-specific schema blueprint methods as macros on
     * Laravel's Blueprint, so migrations keep type-hinting
     * Illuminate\Database\Schema\Blueprint.
     */
    public static function registerBlueprintMacros(): void
    {
        // A float64 vector (vecf64) column; vector() creates vecf32.
        Blueprint::macro('vector64', function (string $column, int $dimensions): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->addColumn('vector64', $column, ['dimensions' => $dimensions]);
        });

        // Laravel 13 ships vectorIndex()/dropVectorIndex(); older releases get
        // equivalents. The MatrixOne grammar builds IVF-Flat unless ->hnsw().
        if (! method_exists(Blueprint::class, 'vectorIndex')) {
            Blueprint::macro('vectorIndex', function ($column, $name = null) {
                /** @var Blueprint $this */
                $columns = (array) $column;
                $name ??= strtolower(str_replace(['-', '.'], '_', $this->getTable().'_'.implode('_', $columns).'_vectorindex'));

                // @phpstan-ignore method.protected (macros are bound to the Blueprint)
                return $this->addCommand('vectorIndex', ['index' => $name, 'columns' => $columns, 'algorithm' => null, 'operatorClass' => 'vector_cosine_ops']);
            });
        }

        if (! method_exists(Blueprint::class, 'dropVectorIndex')) {
            Blueprint::macro('dropVectorIndex', function ($index) {
                /** @var Blueprint $this */
                $name = is_array($index)
                    ? strtolower(str_replace(['-', '.'], '_', $this->getTable().'_'.implode('_', $index).'_vectorindex'))
                    : $index;

                // @phpstan-ignore method.protected (macros are bound to the Blueprint)
                return $this->addCommand('dropVectorIndex', ['index' => $name]);
            });
        }
    }
}
