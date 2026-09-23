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
     * @param  array<int|string, mixed>  $values
     * @param  array<int, string>  $uniqueBy
     * @param  array<int|string, mixed>  $update
     *
     * MatrixOne does not understand the `insert ... as alias` row alias that
     * Laravel uses for MySQL 8, so the `values()` form is always used.
     */
    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update)
    {
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
     * MatrixOne has no `lock in share mode` / `for share`; a shared lock is
     * promoted to `for update`, which is stricter but never less safe.
     */
    protected function compileLock(Builder $query, $value)
    {
        if (! is_string($value)) {
            return 'for update';
        }

        return $value;
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

    /** {@inheritDoc} */
    protected function compileJsonContains($column, $value)
    {
        throw new RuntimeException('JSON contains operations are not supported by MatrixOne.');
    }

    /** {@inheritDoc} */
    protected function compileJsonOverlaps($column, $value)
    {
        throw new RuntimeException('JSON overlaps operations are not supported by MatrixOne.');
    }

    /** {@inheritDoc} */
    protected function compileJsonLength($column, $operator, $value)
    {
        throw new RuntimeException('JSON length operations are not supported by MatrixOne.');
    }

    /**
     * {@inheritDoc}
     *
     * MatrixOne lacks json_contains_path(); a path exists when extracting it
     * yields a value. A key explicitly set to JSON null is reported missing.
     */
    protected function compileJsonContainsKey($column)
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'json_extract('.$field.$path.') is not null';
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
