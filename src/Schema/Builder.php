<?php

namespace MatrixOne\Schema;

use Closure;
use Illuminate\Database\Schema\MySqlBuilder;

/**
 * @property Grammar $grammar
 */
class Builder extends MySqlBuilder
{
    /**
     * {@inheritDoc}
     *
     * MatrixOne cannot drop several tables in one statement.
     */
    public function dropAllTables()
    {
        $tables = $this->getTableListing($this->getCurrentSchemaListing());

        if (empty($tables)) {
            return;
        }

        $this->disableForeignKeyConstraints();

        try {
            foreach ($tables as $table) {
                $this->connection->statement($this->grammar->compileDropTable($table));
            }
        } finally {
            $this->enableForeignKeyConstraints();
        }
    }

    /** {@inheritDoc} */
    public function dropAllViews()
    {
        $views = array_column($this->getViews($this->getCurrentSchemaListing()), 'schema_qualified_name');

        foreach ($views as $view) {
            $this->connection->statement($this->grammar->compileDropView($view));
        }
    }

    /**
     * {@inheritDoc}
     *
     * Uses the MatrixOne blueprint unless a custom resolver was registered.
     */
    protected function createBlueprint($table, ?Closure $callback = null)
    {
        // The resolver is typed non-nullable but starts unset at runtime.
        // @phpstan-ignore isset.property, deadCode.unreachable
        return isset($this->resolver)
            ? parent::createBlueprint($table, $callback)
            : new Blueprint($this->connection, $table, $callback);
    }
}
