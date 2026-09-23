<?php

namespace MatrixOne\Query;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\JoinLateralClause;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

class Grammar extends MySqlGrammar
{
    /**
     * Distance functions keyed by the metric names accepted by the builder.
     *
     * @var array<string, string>
     */
    protected array $vectorDistanceFunctions = [
        'cosine' => 'cosine_distance',
        'l2' => 'l2_distance',
        'inner_product' => 'inner_product',
    ];

    /**
     * {@inheritDoc}
     *
     * MatrixOne evaluates boolean expressions to the strings "true" and
     * "false", and `(bool) "false"` is true in PHP, so the result is mapped
     * to an integer before Laravel casts it.
     */
    public function compileExists(Builder $query)
    {
        $select = $this->compileSelect($query);

        return "select if(exists({$select}), 1, 0) as {$this->wrap('exists')}";
    }

    /**
     * {@inheritDoc}
     *
     * MatrixOne's LAST_INSERT_ID() is wrong for tables with a FULLTEXT index
     * (it reports internal index row IDs), so the generated key is read
     * back with `INSERT ... RETURNING` instead.
     *
     * @param  array<int|string, mixed>  $values
     * @param  string|null  $sequence
     */
    public function compileInsertGetId(Builder $query, $values, $sequence)
    {
        return $this->compileInsert($query, $values).' returning '.$this->wrap($sequence ?: 'id');
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int|string, mixed>  $values
     * @param  array<int, string>  $uniqueBy
     * @param  array<int|string, mixed>  $update
     *
     * MatrixOne does not understand the `insert ... as alias` row alias that
     * Laravel uses for MySQL 8, so the `values()` form is always used.
     */
    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update)
    {
        // MatrixOne rejects assigning a primary key on duplicate ("update
        // primary key on duplicate"), even to its own value, and Laravel's
        // cache upserts every column including the key. The uniqueBy columns
        // already match on a duplicate, so they are left out.
        $update = array_filter(
            $update,
            fn ($value, $key) => ! is_numeric($key) || ! in_array($value, $uniqueBy, true),
            ARRAY_FILTER_USE_BOTH
        );

        if ($update === []) {
            return $this->compileInsertOrIgnore($query, $values);
        }

        $sql = $this->compileInsert($query, $values).' on duplicate key update ';

        $columns = (new Collection($update))->map(function ($value, $key) {
            if (! is_numeric($key)) {
                return $this->wrap((string) $key).' = '.$this->parameter($value);
            }

            /** @var string $value */
            return $this->wrap($value).' = values('.$this->wrap($value).')';
        })->implode(', ');

        return $sql.$columns;
    }

    /**
     * {@inheritDoc}
     *
     * An unconditional DELETE on a table referenced by a foreign key, run
     * while foreign key checks are disabled, corrupts MatrixOne's foreign key
     * metadata (the table can no longer be dropped). A tautological WHERE
     * clause avoids the fast path that causes it.
     */
    protected function compileDeleteWithoutJoins(Builder $query, $table, $where)
    {
        return parent::compileDeleteWithoutJoins($query, $table, trim($where) === '' ? 'where 1 = 1' : $where);
    }

    /**
     * {@inheritDoc}
     *
     * MatrixOne evaluates every SET assignment against the original row, so
     * `data = json_set(data, a), data = json_set(data, b)` would keep only
     * the last change. All JSON paths of one column are merged into a single
     * `json_set(column, path1, value1, path2, value2, ...)` call instead.
     *
     * @param  array<string, mixed>  $values
     */
    protected function compileUpdateColumns(Builder $query, array $values)
    {
        // Each segment is [plain assignment or null, JSON field, JSON path/value pairs].
        /** @var list<array{0: string|null, 1: string, 2: string}> $segments */
        $segments = [];

        foreach ($this->groupJsonUpdateValues($values) as $key => $value) {
            if (! $this->isJsonSelector($key)) {
                $segments[] = [$this->wrap($key).' = '.$this->parameter($value), '', ''];

                continue;
            }

            [$field, $path] = $this->wrapJsonFieldAndPath($key);
            $pair = $path.', '.$this->compileJsonUpdateValue($value);
            $last = count($segments) - 1;

            // Grouped paths of the same column extend the same json_set().
            if ($last >= 0 && $segments[$last][0] === null && $segments[$last][1] === $field) {
                $segments[$last][2] .= $pair;
            } else {
                $segments[] = [null, $field, $pair];
            }
        }

        return implode(', ', array_map(
            fn (array $segment) => $segment[0] ?? "{$segment[1]} = json_set({$segment[1]}{$segment[2]})",
            $segments
        ));
    }

    /**
     * {@inheritDoc}
     *
     * Bindings follow the same grouping as compileUpdateColumns().
     *
     * @param  array<string, array<int, mixed>>  $bindings
     * @param  array<string, mixed>  $values
     * @return array<int, mixed>
     */
    public function prepareBindingsForUpdate(array $bindings, array $values)
    {
        return parent::prepareBindingsForUpdate($bindings, $this->groupJsonUpdateValues($values));
    }

    /**
     * Reorder update values so all JSON paths of a column follow each other,
     * at the position of that column's first path.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function groupJsonUpdateValues(array $values): array
    {
        $groups = [];

        foreach ($values as $key => $value) {
            $group = $this->isJsonSelector($key) ? 'json:'.$this->wrapJsonFieldAndPath($key)[0] : 'column:'.$key;

            $groups[$group][$key] = $value;
        }

        return array_merge(...array_values($groups));
    }

    /**
     * Compile the value of one JSON path update.
     *
     * PDO sends floats as strings, which json_set() would store as JSON
     * strings; casting keeps them JSON numbers, like arrays stay documents.
     */
    protected function compileJsonUpdateValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value), is_float($value) => 'cast(? as json)',
            default => $this->parameter($value),
        };
    }

    /**
     * {@inheritDoc}
     *
     * MatrixOne rejects `with query expansion`, so it fails before reaching
     * the server instead of surfacing an internal error.
     *
     * @param  array<string, mixed>  $where
     */
    public function whereFullText(Builder $query, $where)
    {
        /** @var array<string, mixed> $options */
        $options = $where['options'] ?? [];

        return $this->compileFullTextMatch($where['columns'], $options, $this->parameter($where['value']));
    }

    /**
     * Compile `match (columns) against (value mode)`.
     *
     * Supported options: `mode` => 'natural' (default) or 'boolean'.
     *
     * @param  array<string, mixed>  $options
     */
    public function compileFullTextMatch(mixed $columns, array $options, string $value = '?'): string
    {
        $boolean = ($options['mode'] ?? null) === 'boolean';

        if (($options['expanded'] ?? false) && ! $boolean) {
            throw new RuntimeException('Full-text query expansion is not supported by MatrixOne.');
        }

        /** @var array<int, string> $list */
        $list = (array) $columns;

        return 'match ('.$this->columnize($list).") against ({$value}".($boolean ? ' in boolean mode' : ' in natural language mode').')';
    }

    /**
     * {@inheritDoc}
     *
     * MatrixOne's LIKE is case-sensitive regardless of the column collation,
     * so case-insensitive matches use ILIKE; case-sensitive ones keep
     * `like binary`.
     *
     * @param  array<string, mixed>  $where
     */
    protected function whereLike(Builder $query, $where)
    {
        $where['operator'] = ($where['not'] ? 'not ' : '').($where['caseSensitive'] ? 'like binary' : 'ilike');

        return $this->whereBasic($query, $where);
    }

    /**
     * {@inheritDoc}
     *
     * A plain `like` / `not like` operator becomes ILIKE, matching MySQL's
     * behaviour on the case-insensitive collations Laravel creates tables
     * with. Use `like binary` for a case-sensitive match.
     *
     * @param  array<string, mixed>  $where
     */
    protected function whereBasic(Builder $query, $where)
    {
        $where['operator'] = $this->caseInsensitiveLike($where['operator']);

        return parent::whereBasic($query, $where);
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $having
     */
    protected function compileHaving(array $having)
    {
        if (isset($having['operator'])) {
            $having['operator'] = $this->caseInsensitiveLike($having['operator']);
        }

        return parent::compileHaving($having);
    }

    /**
     * Map `like` / `not like` to their case-insensitive ILIKE forms.
     */
    protected function caseInsensitiveLike(mixed $operator): mixed
    {
        if (! is_string($operator)) {
            return $operator;
        }

        return match (strtolower($operator)) {
            'like' => 'ilike',
            'not like' => 'not ilike',
            default => $operator,
        };
    }

    /**
     * {@inheritDoc}
     *
     * - A shared lock is promoted to `for update` (`lock in share mode` is not
     *   supported, and `for share` only since 4.2.4), which is stricter but
     *   never less safe.
     * - MatrixOne has no `skip locked` / `nowait`. Laravel's database queue
     *   pops jobs with `FOR UPDATE SKIP LOCKED` on MySQL 8 servers, so those
     *   modifiers are dropped: concurrent workers wait for each other instead
     *   of skipping locked rows.
     */
    protected function compileLock(Builder $query, $value)
    {
        if (! is_string($value)) {
            return 'for update';
        }

        $lock = trim((string) preg_replace('/\s+(skip\s+locked|nowait)\s*$/i', '', $value));

        return $lock === $value ? $value : strtolower($lock);
    }

    /**
     * {@inheritDoc}
     *
     * MatrixOne's RAND() does not accept a seed.
     */
    public function compileRandom($seed)
    {
        if ($seed !== '' && $seed !== null && ! is_numeric($seed)) {
            throw new InvalidArgumentException('The seed value must be numeric.');
        }

        return 'RAND()';
    }

    /** {@inheritDoc} */
    public function compileThreadCount()
    {
        return 'select count(*) as `Value` from information_schema.processlist';
    }

    /** {@inheritDoc} */
    public function compileJoinLateral(JoinLateralClause $join, string $expression): string
    {
        throw new RuntimeException('Lateral joins are not supported by MatrixOne.');
    }

    /**
     * {@inheritDoc}
     *
     * MatrixOne has no savepoints: `ROLLBACK TO SAVEPOINT` fails and aborts
     * the transaction. Nested transactions are flattened into the outermost
     * one instead, see the documentation for the rollback semantics.
     */
    public function supportsSavepoints()
    {
        return false;
    }

    /**
     * {@inheritDoc}
     *
     * MatrixOne's json_overlaps() takes exactly two documents, so the path
     * is applied with json_extract() instead of being passed as a third
     * argument.
     */
    protected function compileJsonOverlaps($column, $value)
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        $document = $path === '' ? $field : 'json_extract('.$field.$path.')';

        return 'json_overlaps('.$document.', '.$value.')';
    }

    /**
     * {@inheritDoc}
     *
     * Comparing an extracted JSON boolean with SQL `true` fails in MatrixOne,
     * so both sides are compared as unquoted text.
     */
    protected function wrapJsonBooleanSelector($value)
    {
        return $this->wrapJsonSelector($value);
    }

    /** {@inheritDoc} */
    protected function wrapJsonBooleanValue($value)
    {
        return match ($value) {
            'true' => "'true'",
            'false' => "'false'",
            default => $value,
        };
    }

    /**
     * Compile a vector distance expression for the given column (cosine
     * distance, used by Laravel's built-in vector query methods).
     *
     * @param  string  $column
     * @return string
     */
    public function compileVectorDistanceExpression($column)
    {
        return $this->compileVectorDistance($column, 'cosine');
    }

    /**
     * Compile a vector distance expression using the given metric.
     */
    public function compileVectorDistance(mixed $column, string $metric): string
    {
        $function = $this->vectorDistanceFunctions[$metric] ?? throw new InvalidArgumentException(
            "Unsupported vector distance metric [{$metric}]. Supported: ".implode(', ', array_keys($this->vectorDistanceFunctions)).'.'
        );

        // @phpstan-ignore argument.type
        return "{$function}({$this->wrap($column)}, ?)";
    }

    /**
     * Determine if the grammar supports vector distance queries.
     *
     * @return bool
     */
    public function supportsVectorDistance()
    {
        return true;
    }
}
