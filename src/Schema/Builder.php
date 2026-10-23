<?php

namespace MatrixOne\Schema;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\MySqlBuilder;
use MatrixOne\Query\Processors\MatrixOneProcessor;

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

    /**
     * {@inheritDoc}
     *
     * Foreign keys are read from SHOW CREATE TABLE, which ordinary users may
     * run on their own tables, instead of system catalogs they cannot read.
     * A missing table or a view has no foreign keys, as on MySQL.
     */
    public function getForeignKeys($table)
    {
        [$schema, $table] = $this->parseSchemaAndTable($table);

        $table = $this->connection->getTablePrefix().$table;

        try {
            $results = $this->connection->selectFromWriteConnection(
                $this->grammar->compileForeignKeys($schema, $table)
            );
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1146) {
                return [];
            }

            throw $e;
        }

        $createTable = (array) ($results[0] ?? []);

        if (! is_string($sql = $createTable['Create Table'] ?? null)) {
            return [];
        }

        $processor = $this->connection->getPostProcessor();
        $processor = $processor instanceof MatrixOneProcessor ? $processor : new MatrixOneProcessor;

        return $processor->processCreateTableForeignKeys($sql, $schema ?? $this->connection->getDatabaseName());
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
     * Index names longer than 64 characters are shortened when the index is
     * created, so the same shortening is applied before comparing names.
     *
     * @param  string  $table
     * @param  string|string[]  $index
     * @param  string|null  $type
     */
    public function hasIndex($table, $index, $type = null)
    {
        if (is_string($index)) {
            $index = $this->grammar->shortenIndexName($index);
        }

        return parent::hasIndex($table, $index, $type);
    }
}
