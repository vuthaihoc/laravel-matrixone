<?php

namespace MatrixOne\Query;

use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Support\Stringable;
use InvalidArgumentException;
use MatrixOne\MatrixOneConnection;
use MatrixOne\Support\FullTextQuery;
use MatrixOne\Support\Vector;

/**
 * @property Grammar $grammar
 */
class Builder extends BaseBuilder
{
    /**
     * The time window aggregation: interval(...) sliding(...) fill(...).
     *
     * @var array{column: ExpressionContract|string, interval: array{int, string}, sliding: array{int, string}|null, fill: string|null, fillValue: int|float|null}|null
     */
    public ?array $timeWindow = null;

    /**
     * The SAMPLE() clause: size, unit (rows or percent) and sampled columns.
     *
     * @var array{size: int|float, unit: string, columns: array<int, ExpressionContract|string>|null}|null
     */
    public ?array $sample = null;

    /**
     * The time travel clause read by the "from" table, e.g. {snapshot = 'daily'}.
     */
    public ?string $timeTravel = null;

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
     * A FullTextQuery runs in boolean mode.
     *
     * @param  string|string[]  $columns
     * @param  array{mode?: 'natural'|'boolean'}  $options
     * @return $this
     */
    public function searchFullText(string|array $columns, string|FullTextQuery $value, array $options = []): static
    {
        if ($value instanceof FullTextQuery) {
            [$value, $options] = [$value->toString(), ['mode' => 'boolean']];
        }

        return $this->whereFullText($columns, $value, $options)->orderByFullTextRelevance($columns, $value, $options);
    }

    /**
     * Add a boolean-mode full-text "where" clause built with FullTextQuery.
     * An empty query matches nothing.
     *
     * @param  string|string[]  $columns
     * @return $this
     */
    public function whereFullTextQuery(string|array $columns, FullTextQuery $query, string $boolean = 'and'): static
    {
        if ($query->isEmpty()) {
            return $this->whereRaw('0 = 1', [], $boolean);
        }

        return $this->whereFullText($columns, $query->toString(), ['mode' => 'boolean'], $boolean);
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

    /**
     * Aggregate rows by time window with MatrixOne's INTERVAL clause:
     * timeWindow('ts', '10 seconds', sliding: '5 seconds', fill: 'prev').
     * Select the window bounds as `_wstart` / `_wend` next to aggregates.
     *
     * @param  string  $interval  "<n> <unit>", unit second, minute, hour or day
     * @param  string|null  $sliding  "<n> <unit>"
     * @param  string|null  $fill  prev, next, linear, null, none or value
     * @return $this
     */
    public function timeWindow(
        ExpressionContract|string $column,
        string $interval,
        ?string $sliding = null,
        ?string $fill = null,
        int|float|null $fillValue = null,
    ): static {
        $fill = $fill === null ? null : strtolower($fill);

        if ($fill !== null && ! in_array($fill, ['prev', 'next', 'linear', 'null', 'none', 'value'], true)) {
            throw new InvalidArgumentException('Time window fill must be prev, next, linear, null, none or value.');
        }

        if (($fill === 'value') !== ($fillValue !== null)) {
            throw new InvalidArgumentException('A time window fill value is given with, and only with, fill: "value".');
        }

        $this->timeWindow = [
            'column' => $column,
            'interval' => $this->parseTimeWindowDuration($interval),
            'sliding' => $sliding === null ? null : $this->parseTimeWindowDuration($sliding),
            'fill' => $fill,
            'fillValue' => $fillValue,
        ];

        return $this;
    }

    /**
     * Return a random sample of at most $rows rows (MatrixOne's SAMPLE()).
     * With $columns, only those columns are sampled and the other selected
     * columns act as groups: select('city')->sample(3, 'id')->groupBy('city').
     *
     * @param  array<int, ExpressionContract|string>|ExpressionContract|string|null  $columns
     * @return $this
     */
    public function sample(int $rows, array|ExpressionContract|string|null $columns = null): static
    {
        if ($rows < 1) {
            throw new InvalidArgumentException('The sample size must be at least one row.');
        }

        $this->sample = ['size' => $rows, 'unit' => 'rows', 'columns' => $columns === null ? null : (array) $columns];

        return $this;
    }

    /**
     * Return each row with the given probability (0.01 to 99.99 percent).
     *
     * @param  array<int, ExpressionContract|string>|ExpressionContract|string|null  $columns
     * @return $this
     */
    public function samplePercent(float $percent, array|ExpressionContract|string|null $columns = null): static
    {
        if ($percent < 0.01 || $percent > 99.99) {
            throw new InvalidArgumentException('The sample percentage must be between 0.01 and 99.99.');
        }

        $this->sample = ['size' => $percent, 'unit' => 'percent', 'columns' => $columns === null ? null : (array) $columns];

        return $this;
    }

    /**
     * Read the "from" table as it was when the snapshot was taken.
     *
     * @return $this
     */
    public function asOfSnapshot(string $snapshot): static
    {
        $this->timeTravel = '{snapshot = '.$this->grammar->quoteLiteral($snapshot).'}';

        return $this;
    }

    /**
     * Read the "from" table as it was at the given time (session time zone).
     * The time must be covered by a PITR or be recent enough for MatrixOne
     * to still hold the data.
     *
     * @return $this
     */
    public function asOfTimestamp(DateTimeInterface|string $time): static
    {
        $time = $time instanceof DateTimeInterface ? $time->format('Y-m-d H:i:s.u') : $time;

        $this->timeTravel = '{as of timestamp '.$this->grammar->quoteLiteral($time).'}';

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * Time window and sampled queries return several rows, so aggregates
     * such as count() are computed over them as a subquery.
     *
     * @param  string  $function
     * @param  array<int, ExpressionContract|string>  $columns
     */
    public function aggregate($function, $columns = ['*'])
    {
        if ($this->timeWindow === null && $this->sample === null) {
            return parent::aggregate($function, $columns);
        }

        return $this->newQuery()->fromSub(clone $this, 'aggregate_table')->aggregate($function, $columns);
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>  $columns
     * @return array<int, mixed>
     */
    protected function runPaginationCountQuery($columns = ['*'])
    {
        if ($this->timeWindow === null && $this->sample === null) {
            return parent::runPaginationCountQuery($columns);
        }

        $clone = $this->cloneWithout(['orders', 'limit', 'offset'])->cloneWithoutBindings(['order']);

        return $this->newQuery()->fromSub($clone, 'aggregate_table')->setAggregate('count', ['*'])->get()->all();
    }

    /**
     * Parse "<n> <unit>" for time windows.
     *
     * @return array{int, string}
     */
    protected function parseTimeWindowDuration(string $duration): array
    {
        if (! preg_match('/^\s*(\d+)\s*(second|minute|hour|day)s?\s*$/i', $duration, $matches) || (int) $matches[1] < 1) {
            throw new InvalidArgumentException(
                "Invalid time window duration [{$duration}]: use \"<n> second|minute|hour|day\" (MatrixOne supports no other unit)."
            );
        }

        return [(int) $matches[1], strtolower($matches[2])];
    }
}
