<?php

namespace MatrixOne\Query;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Support\Stringable;
use InvalidArgumentException;
use MatrixOne\MatrixOneConnection;
use MatrixOne\Support\Vector;

/**
 * @property Grammar $grammar
 */
class Builder extends BaseBuilder
{
    /**
     * {@inheritDoc}
     *
     * MatrixOne refuses to truncate a table referenced by a foreign key, even
     * with foreign key checks disabled. Such tables are emptied with DELETE.
     */
    public function truncate()
    {
        try {
            parent::truncate();
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'referenced by some foreign key constraint')) {
                throw $e;
            }

            $this->newQuery()->from($this->from)->delete();
        }
    }

    /**
     * {@inheritDoc}
     *
     * Uses MatrixOne's cosine_distance() on every supported Laravel version
     * (Laravel 12 hard-codes the pgvector operator here).
     *
     * @param  ExpressionContract|string  $column
     * @param  Arrayable<int, mixed>|array<int, mixed>|string  $vector
     * @param  string|null  $as
     * @return $this
     */
    public function selectVectorDistance($column, $vector, $as = null)
    {
        return $this->selectVectorDistanceUsing('cosine', $column, $this->resolveVector($vector), $as ?? (is_string($column) ? $column.'_distance' : null));
    }

    /**
     * {@inheritDoc}
     *
     * @param  ExpressionContract|string  $column
     * @param  Arrayable<int, mixed>|array<int, mixed>|string  $vector
     * @param  float  $maxDistance
     * @param  string  $boolean
     * @return $this
     */
    public function whereVectorDistanceLessThan($column, $vector, $maxDistance, $boolean = 'and')
    {
        return $this->whereVectorDistanceUsing('cosine', $column, $this->resolveVector($vector), '<=', $maxDistance, $boolean);
    }

    /**
     * {@inheritDoc}
     *
     * @param  ExpressionContract|string  $column
     * @param  Arrayable<int, mixed>|array<int, mixed>|string  $vector
     * @return $this
     */
    public function orderByVectorDistance($column, $vector)
    {
        return $this->orderByVectorDistanceUsing('cosine', $column, $this->resolveVector($vector));
    }

    /**
     * Resolve a vector argument the way Laravel does: a plain string is text
     * to embed, unless it already is a vector literal such as "[1,2,3]".
     *
     * @param  Arrayable<int, mixed>|array<int, mixed>|string  $vector
     * @return Arrayable<int, mixed>|array<int, mixed>|string
     */
    protected function resolveVector(mixed $vector): Arrayable|array|string
    {
        if (is_string($vector) && ! str_starts_with(ltrim($vector), '[') && method_exists(Stringable::class, 'toEmbeddings')) {
            // Available from Laravel 13 (laravel/ai embeddings).
            // @phpstan-ignore method.notFound
            return (new Stringable($vector))->toEmbeddings(cache: true);
        }

        if (! is_string($vector) && ! is_array($vector) && ! $vector instanceof Arrayable) {
            throw new InvalidArgumentException('The vector must be an array, an Arrayable or a string.');
        }

        return $vector;
    }

    /**
     * {@inheritDoc}
     *
     * Inside MatrixOneConnection::withCompatibilityRewrites(), `null as alias`
     * placeholders become `cast(null as double) as alias`.
     *
     * @param  string  $expression
     * @param  array<int, mixed>  $bindings
     * @return $this
     */
    public function selectRaw($expression, array $bindings = [])
    {
        if ($this->connection instanceof MatrixOneConnection
            && $this->connection->usesCompatibilityRewrites()
            && is_string($expression)) {
            $expression = (string) preg_replace('/^\s*null\s+as\s+/i', 'cast(null as double) as ', $expression);
        }

        return parent::selectRaw($expression, $bindings);
    }

    /**
     * {@inheritDoc}
     *
     * Inside MatrixOneConnection::withCompatibilityRewrites(), a subquery
     * selecting a single column with `limit 1` selects `max(column)` instead.
     *
     * @param  \Closure|BaseBuilder|\Illuminate\Database\Eloquent\Builder<Model>|string  $query
     * @param  string  $as
     * @return $this
     */
    public function selectSub($query, $as)
    {
        if ($this->connection instanceof MatrixOneConnection && $this->connection->usesCompatibilityRewrites()) {
            if ($query instanceof \Closure) {
                $callback = $query;
                $callback($query = $this->forSubQuery());
            }

            if ($query instanceof BaseBuilder
                && $query->limit === 1
                && empty($query->offset)
                && is_array($query->columns)
                && count($query->columns) === 1
                && is_string($query->columns[0])) {
                $query->limit = null;
                $query->orders = null;
                $query->columns = [new Expression('max('.$this->grammar->wrap($query->columns[0]).')')];
            }
        }

        return parent::selectSub($query, $as);
    }

    /**
     * Add a case-insensitive equality "where" clause.
     *
     * MatrixOne compares strings case-sensitively even on `_ci` collations.
     * This compiles to `lower(column) = lower(?)`, which cannot use an index;
     * for large tables store normalized values instead (see the Lowercase
     * cast) and compare with a plain where().
     *
     * @return $this
     */
    public function whereIgnoreCase(ExpressionContract|string $column, ?string $value, string $boolean = 'and'): static
    {
        if ($value === null) {
            return $this->whereNull($column, $boolean);
        }

        return $this->whereRaw('lower('.$this->grammar->wrap($column).') = lower(?)', [$value], $boolean);
    }

    /**
     * Add a case-insensitive equality "or where" clause.
     *
     * @return $this
     */
    public function orWhereIgnoreCase(ExpressionContract|string $column, ?string $value): static
    {
        return $this->whereIgnoreCase($column, $value, 'or');
    }

    /**
     * Add a case-insensitive "where in" clause.
     *
     * @param  array<int, string>  $values
     * @return $this
     */
    public function whereInIgnoreCase(ExpressionContract|string $column, array $values, string $boolean = 'and', bool $not = false): static
    {
        if ($values === []) {
            return $not ? $this : $this->whereRaw('0 = 1', [], $boolean);
        }

        $placeholders = implode(', ', array_fill(0, count($values), 'lower(?)'));

        return $this->whereRaw(
            'lower('.$this->grammar->wrap($column).')'.($not ? ' not in ' : ' in ').'('.$placeholders.')',
            array_values($values),
            $boolean
        );
    }

    /**
     * Add a case-insensitive "where not in" clause.
     *
     * @param  array<int, string>  $values
     * @return $this
     */
    public function whereNotInIgnoreCase(ExpressionContract|string $column, array $values, string $boolean = 'and'): static
    {
        return $this->whereInIgnoreCase($column, $values, $boolean, true);
    }

    /**
     * Add the full-text relevance score to the select list.
     *
     * The columns must match a FULLTEXT index. MatrixOne scores with TF-IDF by
     * default; set the `ft_relevancy_algorithm` session variable to 'BM25'
     * to switch.
     *
     * @param  string|string[]  $columns
     * @param  array{mode?: 'natural'|'boolean'}  $options
     * @return $this
     */
    public function selectFullTextRelevance(string|array $columns, string $value, string $as = 'relevance', array $options = []): static
    {
        $this->addBinding($value, 'select');

        return $this->addSelect(new Expression(
            $this->grammar->compileFullTextMatch($columns, $options).' as '.$this->grammar->wrap($as)
        ));
    }

    /**
     * Order the query by full-text relevance (most relevant first).
     *
     * @param  string|string[]  $columns
     * @param  array{mode?: 'natural'|'boolean'}  $options
     * @return $this
     */
    public function orderByFullTextRelevance(string|array $columns, string $value, array $options = [], string $direction = 'desc'): static
    {
        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Order direction must be "asc" or "desc".');
        }

        $this->addBinding($value, $this->unions ? 'unionOrder' : 'order');

        $this->{$this->unions ? 'unionOrders' : 'orders'}[] = [
            'column' => new Expression($this->grammar->compileFullTextMatch($columns, $options)),
            'direction' => $direction,
        ];

        return $this;
    }

    /**
     * Search a FULLTEXT index and order the matches by relevance.
     *
     * @param  string|string[]  $columns
     * @param  array{mode?: 'natural'|'boolean'}  $options
     * @return $this
     */
    public function searchFullText(string|array $columns, string $value, array $options = []): static
    {
        return $this->whereFullText($columns, $value, $options)->orderByFullTextRelevance($columns, $value, $options);
    }

    /**
     * Add a vector distance to the select list.
     *
     * @param  Arrayable<int, mixed>|array<int, mixed>|string  $vector
     * @return $this
     */
    public function selectVectorDistanceUsing(
        string $metric,
        ExpressionContract|string $column,
        Arrayable|array|string $vector,
        ?string $as = null,
    ): static {
        $as ??= (is_string($column) ? $column : 'vector').'_distance';

        $this->addBinding(Vector::toLiteral($vector), 'select');

        return $this->addSelect(new Expression(
            $this->grammar->compileVectorDistance($column, $metric).' as '.$this->grammar->wrap($as)
        ));
    }

    /**
     * Add a vector distance "where" clause to the query.
     *
     * @param  Arrayable<int, mixed>|array<int, mixed>|string  $vector
     * @return $this
     */
    public function whereVectorDistanceUsing(
        string $metric,
        ExpressionContract|string $column,
        Arrayable|array|string $vector,
        string $operator,
        float|int $value,
        string $boolean = 'and',
    ): static {
        if (! in_array($operator, ['<', '<=', '>', '>=', '=', '!=', '<>'], true)) {
            throw new InvalidArgumentException("Invalid vector distance operator [{$operator}].");
        }

        return $this->whereRaw(
            $this->grammar->compileVectorDistance($column, $metric)." {$operator} ?",
            [Vector::toLiteral($vector), $value],
            $boolean,
        );
    }

    /**
     * Order the query by the distance to the given vector (nearest first).
     *
     * @param  Arrayable<int, mixed>|array<int, mixed>|string  $vector
     * @return $this
     */
    public function orderByVectorDistanceUsing(
        string $metric,
        ExpressionContract|string $column,
        Arrayable|array|string $vector,
        string $direction = 'asc',
    ): static {
        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Order direction must be "asc" or "desc".');
        }

        $this->addBinding(Vector::toLiteral($vector), $this->unions ? 'unionOrder' : 'order');

        $this->{$this->unions ? 'unionOrders' : 'orders'}[] = [
            'column' => new Expression($this->grammar->compileVectorDistance($column, $metric)),
            'direction' => $direction,
        ];

        return $this;
    }

    /**
     * Get the $limit rows nearest to the given vector.
     *
     * @param  Arrayable<int, mixed>|array<int, mixed>|string  $vector
     * @return $this
     */
    public function nearestTo(
        ExpressionContract|string $column,
        Arrayable|array|string $vector,
        int $limit = 10,
        string $metric = 'cosine',
    ): static {
        return $this->orderByVectorDistanceUsing($metric, $column, $vector)->limit($limit);
    }
}
