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
