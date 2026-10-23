<?php

namespace MatrixOne\Query\Processors;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\MySqlProcessor;

class MatrixOneProcessor extends MySqlProcessor
{
    /**
     * Integer types whose display width MatrixOne reports as a bit size,
     * e.g. "BIGINT UNSIGNED(64)" or "TINYINT(8)".
     *
     * @var string[]
     */
    protected array $integerTypes = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'];

    /**
     * {@inheritDoc}
     *
     * The grammar compiles `INSERT ... RETURNING <key>`, so the ID is read
     * from the returned row rather than from LAST_INSERT_ID().
     *
     * @param  array<int|string, mixed>  $values
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        $connection = $query->getConnection();

        if (! $connection instanceof Connection) {
            return parent::processInsertGetId($query, $sql, $values, $sequence);
        }

        $results = $connection->selectFromWriteConnection($sql, $values);

        // select() does not flag the connection as modified, which sticky
        // read/write connections rely on to route later reads to the writer.
        $connection->recordsHaveBeenModified();

        $row = (array) ($results[0] ?? []);

        $id = $row[$sequence ?: 'id'] ?? (array_values($row)[0] ?? null);

        return is_numeric($id) ? (int) $id : $id;
    }

    /** {@inheritDoc} */
    public function processColumns($results)
    {
        $columns = parent::processColumns(array_map(function ($result) {
            $result = (object) $result;

            [$typeName, $type] = $this->normalizeType((string) $result->type_name, (string) $result->type);

            $result->type_name = $typeName;
            $result->type = $type;
            $result->expression = $result->expression ?: null;

            return (array) $result;
        }, $results));

        return $columns;
    }

    /** {@inheritDoc} */
    public function processIndexes($results)
    {
        return array_map(function ($result) {
            $result = (object) $result;
            $name = strtolower((string) $result->name);
            $type = strtolower((string) $result->type);

            return [
                'name' => $name,
                // Vector indexes are backed by several hidden tables and are
                // reported once per table, so the column list is de-duplicated.
                'columns' => $result->columns ? array_values(array_unique(explode(',', (string) $result->columns))) : [],
                'type' => $type === '' ? 'btree' : $type,
                'unique' => (bool) (int) $result->unique,
                'primary' => $name === 'primary',
            ];
        }, $results);
    }

    /**
     * Read the foreign keys of a SHOW CREATE TABLE statement, in the shape of
     * processForeignKeys(). References without a schema point into $schema.
     *
     * @return list<array{name: string, columns: list<string>, foreign_schema: string, foreign_table: string, foreign_columns: list<string>, on_update: string, on_delete: string}>
     */
    public function processCreateTableForeignKeys(string $sql, string $schema): array
    {
        $identifier = '`(?:[^`]|``)*`';
        $identifiers = "{$identifier}(?:\\s*,\\s*{$identifier})*";
        $action = 'RESTRICT|CASCADE|SET NULL|SET DEFAULT|NO ACTION';

        preg_match_all(
            "/^\\s*CONSTRAINT\\s+({$identifier})\\s+FOREIGN KEY\\s*\\(({$identifiers})\\)"
            ."\\s+REFERENCES\\s+({$identifier})(?:\\.({$identifier}))?\\s*\\(({$identifiers})\\)"
            ."(?:\\s+ON DELETE\\s+({$action}))?(?:\\s+ON UPDATE\\s+({$action}))?/mi",
            $sql,
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
        );

        return array_map(fn (array $match) => [
            'name' => $this->unquoteIdentifier((string) $match[1]),
            'columns' => $this->unquoteIdentifiers((string) $match[2]),
            'foreign_schema' => $match[4] === null ? $schema : $this->unquoteIdentifier((string) $match[3]),
            'foreign_table' => $this->unquoteIdentifier((string) ($match[4] ?? $match[3])),
            'foreign_columns' => $this->unquoteIdentifiers((string) $match[5]),
            'on_update' => strtolower($match[7] ?? 'restrict'),
            'on_delete' => strtolower($match[6] ?? 'restrict'),
        ], $matches);
    }

    /**
     * Split a comma-separated list of backtick-quoted identifiers.
     *
     * @return list<string>
     */
    protected function unquoteIdentifiers(string $list): array
    {
        preg_match_all('/`(?:[^`]|``)*`/', $list, $matches);

        return array_map($this->unquoteIdentifier(...), $matches[0]);
    }

    protected function unquoteIdentifier(string $identifier): string
    {
        return str_replace('``', '`', substr($identifier, 1, -1));
    }

    /**
     * Convert MatrixOne's upper-case type metadata to the lower-case MySQL
     * shape Laravel expects ("BIGINT UNSIGNED(64)" becomes "bigint unsigned").
     *
     * @return array{0: string, 1: string}
     */
    protected function normalizeType(string $typeName, string $type): array
    {
        $typeName = strtolower($typeName);
        $type = strtolower($type);
        $base = explode(' ', $typeName)[0];

        if (in_array($base, $this->integerTypes, true) || str_ends_with($type, '(0)')) {
            $type = (string) preg_replace('/\(\d+\)$/', '', $type);
        }

        return [$base, $type];
    }
}
