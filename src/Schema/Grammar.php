<?php

namespace MatrixOne\Schema;

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Support\Fluent;
use InvalidArgumentException;
use RuntimeException;

class Grammar extends MySqlGrammar
{
    /**
     * Databases created and managed by MatrixOne itself.
     *
     * @var string[]
     */
    protected array $systemSchemas = [
        'information_schema', 'mysql', 'mo_catalog', 'mo_debug', 'mo_task', 'system', 'system_metrics',
    ];

    /**
     * Vector index algorithms MatrixOne can build.
     *
     * @var string[]
     */
    protected array $vectorIndexAlgorithms = ['ivfflat', 'hnsw'];

    /** {@inheritDoc} */
    protected function compileSchemaWhereClause($schema, $column)
    {
        if (empty($schema)) {
            return $column.' not in ('.$this->quoteString($this->systemSchemas).')';
        }

        return parent::compileSchemaWhereClause($schema, $column);
    }

    /**
     * {@inheritDoc}
     *
     * Boolean expressions come back as the strings "true"/"false", so the
     * `default` flag is computed as an integer.
     */
    public function compileSchemas()
    {
        return 'select schema_name as name, if(schema_name = schema(), 1, 0) as `default` from information_schema.schemata where '
            .$this->compileSchemaWhereClause(null, 'schema_name')
            .' order by schema_name';
    }

    /**
     * {@inheritDoc}
     *
     * `(bool) "false"` is true in PHP, so the existence flag is returned as
     * an integer instead of MatrixOne's "true"/"false" strings.
     */
    public function compileTableExists($schema, $table)
    {
        return sprintf(
            'select if(exists (select 1 from information_schema.tables where '
            ."table_schema = %s and table_name = %s and table_type in ('BASE TABLE', 'SYSTEM VERSIONED')), 1, 0) as `exists`",
            $schema ? $this->quoteString($schema) : 'schema()',
            $this->quoteString($table)
        );
    }

    /**
     * {@inheritDoc}
     *
     * Hidden columns MatrixOne adds for tables without a primary key
     * (`__mo_fake_pk_col`) and for secondary indexes are excluded.
     */
    public function compileColumns($schema, $table)
    {
        return sprintf(
            'select column_name as `name`, data_type as `type_name`, column_type as `type`, '
            .'collation_name as `collation`, is_nullable as `nullable`, '
            .'column_default as `default`, column_comment as `comment`, '
            .'generation_expression as `expression`, extra as `extra` '
            .'from information_schema.columns where table_schema = %s and table_name = %s '
            ."and column_name not like '\\_\\_mo\\_%%' "
            .'order by ordinal_position asc',
            $schema ? $this->quoteString($schema) : 'schema()',
            $this->quoteString($table)
        );
    }

    /**
     * {@inheritDoc}
     *
     * `not non_unique` evaluates to "true"/"false" strings in MatrixOne, so
     * uniqueness is computed as an integer instead.
     */
    public function compileIndexes($schema, $table)
    {
        return sprintf(
            'select index_name as `name`, group_concat(column_name order by seq_in_index) as `columns`, '
            .'index_type as `type`, if(non_unique = 0, 1, 0) as `unique` '
            .'from information_schema.statistics where table_schema = %s and table_name = %s '
            ."and column_name not like '\\_\\_mo\\_%%' "
            .'group by index_name, index_type, non_unique',
            $schema ? $this->quoteString($schema) : 'schema()',
            $this->quoteString($table)
        );
    }

    /**
     * {@inheritDoc}
     *
     * MatrixOne leaves information_schema.key_column_usage empty for foreign
     * keys, so they are read from mo_catalog.mo_foreign_keys instead.
     */
    public function compileForeignKeys($schema, $table)
    {
        return sprintf(
            'select constraint_name as `name`, '
            .'group_concat(column_name order by column_id) as `columns`, '
            .'refer_db_name as `foreign_schema`, '
            .'refer_table_name as `foreign_table`, '
            .'group_concat(refer_column_name order by refer_column_id) as `foreign_columns`, '
            .'on_update as `on_update`, '
            .'on_delete as `on_delete` '
            .'from mo_catalog.mo_foreign_keys where db_name = %s and table_name = %s '
            .'group by constraint_name, refer_db_name, refer_table_name, on_update, on_delete',
            $schema ? $this->quoteString($schema) : 'schema()',
            $this->quoteString($table)
        );
    }

    /**
     * {@inheritDoc}
     *
     * MatrixOne only accepts an AUTO_INCREMENT starting value as a table
     * option of CREATE TABLE, so it is appended here.
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command)
    {
        $sql = parent::compileCreate($blueprint, $command);

        if (! is_null($value = $this->getAutoIncrementStartingValue($blueprint))) {
            $sql .= ' auto_increment = '.$value;
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     *
     * @return string|null
     */
    public function compileAutoIncrementStartingValues(Blueprint $blueprint, Fluent $command)
    {
        /** @var Fluent<string, mixed> $column */
        $column = $command->column;

        if (! $column->autoIncrement || ! $column->get('startingValue', $column->get('from'))) {
            return null;
        }

        if ($this->creatingTable($blueprint)) {
            return null;
        }

        throw new RuntimeException('MatrixOne can only set an auto-increment starting value when creating a table.');
    }

    /**
     * {@inheritDoc}
     *
     * MatrixOne has no RENAME INDEX, so the index is dropped and re-created
     * with the same columns under the new name.
     *
     * @return string[]
     *
     * @phpstan-ignore method.childReturnType
     */
    public function compileRenameIndex(Blueprint $blueprint, Fluent $command)
    {
        $indexes = $this->connection->getSchemaBuilder()->getIndexes($blueprint->getTable());

        $index = collect($indexes)->firstWhere('name', strtolower($this->shortenIndexName((string) $command->from)));

        if (! is_array($index)) {
            throw new RuntimeException("Index [{$command->from}] does not exist on table [{$blueprint->getTable()}].");
        }

        if ($index['primary']) {
            throw new RuntimeException('The primary key index cannot be renamed.');
        }

        $type = match (true) {
            $index['unique'] => 'unique',
            $index['type'] === 'fulltext' => 'fulltext',
            default => 'index',
        };

        return array_values(array_filter([
            $this->compileDropIndex($blueprint, new Fluent(['index' => $command->from])),
            $this->compileKey($blueprint, new Fluent(['index' => $command->to, 'columns' => $index['columns']]), $type),
        ]));
    }

    /**
     * {@inheritDoc}
     *
     * MatrixOne rejects `USING <algorithm>` and online DDL options on regular
     * indexes, so they are omitted. A full-text index may name a parser,
     * e.g. `$table->fullText('body')->parser('ngram')`. Index names longer
     * than 64 characters are shortened, and JSON columns cannot be indexed.
     *
     * @return string|null
     *
     * @phpstan-ignore method.childReturnType
     */
    protected function compileKey(Blueprint $blueprint, Fluent $command, $type)
    {
        if (in_array($type, ['index', 'unique'], true)
            && ($jsonColumns = $this->jsonColumnsIn($blueprint, (array) $command->columns)) !== []) {
            if ($this->connection->getConfig('ignore_json_indexes')) {
                return null;
            }

            throw new RuntimeException(sprintf(
                'MatrixOne cannot index JSON columns [%s] on table [%s]. Drop the index, or set "ignore_json_indexes" on the connection to skip such indexes.',
                implode(', ', $jsonColumns),
                $blueprint->getTable()
            ));
        }

        return sprintf('alter table %s add %s %s(%s)%s',
            $this->wrapTable($blueprint),
            $type,
            $this->wrap($this->shortenIndexName((string) $command->index)),
            $this->columnize($command->columns),
            $type === 'fulltext' && $command->parser ? ' with parser '.$this->parserName($command->parser) : ''
        );
    }

    /**
     * Shorten an index name to MatrixOne's 64-character identifier limit.
     *
     * Long names keep a readable prefix plus a hash of the full name, so the
     * same Laravel-generated name always maps to the same index when it is
     * created, dropped, renamed or looked up.
     */
    public function shortenIndexName(string $name): string
    {
        if (strlen($name) <= 64) {
            return $name;
        }

        return substr($name, 0, 56).'_'.substr(md5($name), 0, 7);
    }

    /**
     * Compile a vector index command (IVF-Flat by default).
     *
     * @return string|string[]
     */
    public function compileVectorIndex(Blueprint $blueprint, Fluent $command)
    {
        $algorithm = strtolower((string) ($command->algorithm ?: 'ivfflat'));

        if (! in_array($algorithm, $this->vectorIndexAlgorithms, true)) {
            throw new InvalidArgumentException("Unsupported vector index algorithm [{$algorithm}].");
        }

        $options = [];

        if ($algorithm === 'ivfflat' && $command->lists) {
            $options[] = 'lists = '.(int) $command->lists;
        }

        if ($algorithm === 'hnsw') {
            foreach (['m' => 'm', 'efConstruction' => 'ef_construction', 'efSearch' => 'ef_search'] as $key => $option) {
                if ($command->{$key}) {
                    $options[] = $option.' = '.(int) $command->{$key};
                }
            }
        }

        $options[] = 'op_type '.$this->quoteString($this->vectorOperatorClass($command->operatorClass));

        $index = $this->shortenIndexName((string) $command->index);

        $sql = sprintf('create index %s using %s on %s (%s) %s',
            $this->wrap($index),
            $algorithm,
            $this->wrapTable($blueprint),
            $this->columnize($command->columns),
            implode(' ', $options)
        );

        // HNSW indexes are experimental and have to be enabled per session.
        return $algorithm === 'hnsw' ? ['set experimental_hnsw_index = 1', $sql] : $sql;
    }

    /** {@inheritDoc} */
    public function compileDropIndex(Blueprint $blueprint, Fluent $command)
    {
        $index = $this->shortenIndexName((string) $command->index);

        return "alter table {$this->wrapTable($blueprint)} drop index {$this->wrap($index)}";
    }

    /** {@inheritDoc} */
    public function compileDropUnique(Blueprint $blueprint, Fluent $command)
    {
        return $this->compileDropIndex($blueprint, $command);
    }

    /**
     * Compile a drop vector index command.
     */
    public function compileDropVectorIndex(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileDropIndex($blueprint, $command);
    }

    /**
     * Compile the SQL to drop a single table (MatrixOne cannot drop several
     * tables in one statement).
     */
    public function compileDropTable(string $table): string
    {
        return 'drop table '.$this->escapeNames([$table])[0];
    }

    /**
     * Compile the SQL to drop a single view.
     */
    public function compileDropView(string $view): string
    {
        return 'drop view '.$this->escapeNames([$view])[0];
    }

    /** {@inheritDoc} */
    protected function typeSet(Fluent $column)
    {
        throw new RuntimeException('The SET column type is not supported by MatrixOne.');
    }

    /** {@inheritDoc} */
    protected function typeYear(Fluent $column)
    {
        if ($column->useCurrent) {
            $column->default = new Expression('(year(current_date()))');
        }

        // MatrixOne has no YEAR type; a small integer covers its range.
        return 'smallint';
    }

    /** {@inheritDoc} */
    protected function typeGeometry(Fluent $column)
    {
        throw new RuntimeException('Spatial column types are not supported by MatrixOne.');
    }

    /** {@inheritDoc} */
    protected function typeGeography(Fluent $column)
    {
        throw new RuntimeException('Spatial column types are not supported by MatrixOne.');
    }

    /**
     * {@inheritDoc}
     *
     * Laravel's `vector()` maps to a float32 vector; use `vector64()` on the
     * MatrixOne blueprint for float64 elements.
     */
    protected function typeVector(Fluent $column)
    {
        return 'vecf32('.$this->vectorDimensions($column).')';
    }

    /**
     * Create the column definition for a float64 vector type.
     */
    protected function typeVector64(Fluent $column): string
    {
        return 'vecf64('.$this->vectorDimensions($column).')';
    }

    /**
     * {@inheritDoc}
     *
     * MatrixOne rejects any default value on a JSON column.
     */
    protected function modifyDefault(Blueprint $blueprint, Fluent $column)
    {
        if ($this->isJsonColumn($column) && ! is_null($column->default)) {
            if ($this->connection->getConfig('ignore_json_defaults')) {
                return null;
            }

            throw new RuntimeException(sprintf(
                'MatrixOne does not support default values on JSON column [%s]. Remove the default, or set "ignore_json_defaults" on the connection to drop it (the column then becomes nullable).',
                $column->name
            ));
        }

        return parent::modifyDefault($blueprint, $column);
    }

    /**
     * {@inheritDoc}
     *
     * When a JSON default is dropped (`ignore_json_defaults`), the column is
     * made nullable so inserts that relied on the default keep working.
     */
    protected function modifyNullable(Blueprint $blueprint, Fluent $column)
    {
        if ($this->isJsonColumn($column) && ! is_null($column->default) && $this->connection->getConfig('ignore_json_defaults')) {
            return ' null';
        }

        return parent::modifyNullable($blueprint, $column);
    }

    /** {@inheritDoc} */
    protected function modifyVirtualAs(Blueprint $blueprint, Fluent $column)
    {
        if (! is_null($column->virtualAs) || ! is_null($column->virtualAsJson)) {
            throw new RuntimeException('Generated columns are not supported by MatrixOne.');
        }

        return null;
    }

    /** {@inheritDoc} */
    protected function modifyStoredAs(Blueprint $blueprint, Fluent $column)
    {
        if (! is_null($column->storedAs) || ! is_null($column->storedAsJson)) {
            throw new RuntimeException('Generated columns are not supported by MatrixOne.');
        }

        return null;
    }

    /**
     * Determine if the column definition is a JSON column.
     */
    protected function isJsonColumn(Fluent $column): bool
    {
        return in_array($column->type, ['json', 'jsonb'], true);
    }

    /**
     * Get the JSON columns among the given index columns, looking at the
     * columns added by the blueprint and, for existing tables, the catalog.
     *
     * @param  array<int, mixed>  $columns
     * @return string[]
     */
    protected function jsonColumnsIn(Blueprint $blueprint, array $columns): array
    {
        $columns = array_values(array_filter($columns, 'is_string'));

        if ($columns === []) {
            return [];
        }

        $types = [];

        foreach ($blueprint->getColumns() as $column) {
            $types[(string) $column->name] = $this->isJsonColumn($column);
        }

        $unknown = array_diff($columns, array_keys($types));

        if ($unknown !== [] && ! $this->creatingTable($blueprint)) {
            foreach ($this->connection->getSchemaBuilder()->getColumns($blueprint->getTable()) as $column) {
                $types[$column['name']] ??= $column['type_name'] === 'json';
            }
        }

        return array_values(array_filter($columns, fn ($column) => $types[$column] ?? false));
    }

    /**
     * Get the vector dimensions, which MatrixOne requires.
     */
    protected function vectorDimensions(Fluent $column): int
    {
        if (! isset($column->dimensions) || ! is_numeric($column->dimensions) || (int) $column->dimensions < 1) {
            throw new InvalidArgumentException("MatrixOne vector column [{$column->name}] requires a dimension count.");
        }

        return (int) $column->dimensions;
    }

    /**
     * Normalize a vector operator class. Laravel's default (`vector_cosine_ops`)
     * matches MatrixOne's naming, the others are l2 and inner product.
     */
    protected function vectorOperatorClass(mixed $operatorClass): string
    {
        $operatorClass = is_string($operatorClass) && $operatorClass !== '' ? strtolower($operatorClass) : 'vector_cosine_ops';

        return match ($operatorClass) {
            'cosine', 'vector_cosine_ops' => 'vector_cosine_ops',
            'l2', 'vector_l2_ops' => 'vector_l2_ops',
            'ip', 'inner_product', 'vector_ip_ops' => 'vector_ip_ops',
            default => throw new InvalidArgumentException("Unsupported vector operator class [{$operatorClass}]."),
        };
    }

    /**
     * Validate a full-text parser name before it is embedded in SQL.
     */
    protected function parserName(mixed $parser): string
    {
        if (! is_string($parser) || ! preg_match('/^[a-z0-9_]+$/i', $parser)) {
            throw new InvalidArgumentException('Invalid full-text parser name.');
        }

        return $parser;
    }

    /**
     * Get the starting value of the table's auto-increment column, if any.
     */
    protected function getAutoIncrementStartingValue(Blueprint $blueprint): ?int
    {
        foreach ($blueprint->getAddedColumns() as $column) {
            $value = $column->get('startingValue', $column->get('from'));

            if ($column->autoIncrement && is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Determine if the blueprint creates its table.
     */
    protected function creatingTable(Blueprint $blueprint): bool
    {
        foreach ($blueprint->getCommands() as $command) {
            if ($command->name === 'create') {
                return true;
            }
        }

        return false;
    }
}
