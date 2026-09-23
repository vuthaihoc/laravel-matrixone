<?php

namespace MatrixOne\Query\Processors;

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
