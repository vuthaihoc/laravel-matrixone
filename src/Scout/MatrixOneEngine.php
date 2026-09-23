<?php

namespace MatrixOne\Scout;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\DatabaseEngine;
use MatrixOne\MatrixOneConnection;
use MatrixOne\Query\Builder as QueryBuilder;
use MatrixOne\Support\FullTextQuery;

/**
 * Scout's database engine tuned for MatrixOne (SCOUT_DRIVER=matrixone).
 *
 * - Full-text matches are ordered by relevance: unlike MySQL, MatrixOne does
 *   not sort MATCH ... AGAINST results on its own.
 * - Search terms match any of their words, like MySQL's natural language
 *   mode (MatrixOne's natural language mode only matches words appearing
 *   together); models declaring ['mode' => 'boolean'] keep the raw query
 *   with its operators.
 * - Semantic search (->semantic()) and hybrid search (->hybrid()), which
 *   Scout enables for PostgreSQL only, run on MatrixOne vector columns.
 */
class MatrixOneEngine extends DatabaseEngine
{
    /**
     * Determine if the model's connection can run vector queries.
     *
     * @param  Model  $model
     */
    protected function supportsVectorSearch($model): bool
    {
        return $model->getConnection() instanceof MatrixOneConnection;
    }

    /**
     * Add the text search constraints.
     *
     * Same as Scout's database engine, except that full-text matches are
     * selected through a subquery: MatrixOne cannot use a FULLTEXT index for
     * MATCH ... AGAINST combined with other predicates by OR, which is how
     * Scout mixes LIKE and full-text columns.
     *
     * @param  EloquentBuilder<Model>  $query
     * @param  Builder<Model>  $builder
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $prefixColumns
     * @param  array<int, string>  $fullTextColumns
     * @return EloquentBuilder<Model>
     */
    protected function addTextSearchConstraints($query, Builder $builder, array $columns, array $prefixColumns = [], array $fullTextColumns = [])
    {
        if (method_exists($builder->model, 'toSearchableEmbedding')) {
            $embeddingColumn = $this->embeddingColumn($builder->model);

            $columns = array_values(array_diff($columns, [$embeddingColumn]));
            $prefixColumns = array_values(array_diff($prefixColumns, [$embeddingColumn]));
            $fullTextColumns = array_values(array_diff($fullTextColumns, [$embeddingColumn]));
        }

        if (blank($builder->query)) {
            return $query;
        }

        $search = (string) $builder->query;
        $model = $builder->model;

        // Provided by the Searchable trait.
        /** @var string $scoutKey */
        $scoutKey = $model->getScoutKeyName(); // @phpstan-ignore method.notFound

        return $query->where(function ($query) use ($search, $model, $builder, $scoutKey, $columns, $prefixColumns, $fullTextColumns) {
            $canSearchPrimaryKey = ctype_digit($search)
                && in_array($model->getKeyType(), ['int', 'integer'], true)
                && in_array($scoutKey, $columns, true);

            if ($canSearchPrimaryKey) {
                $query->orWhere($model->getQualifiedKeyName(), $search);
            }

            foreach ($columns as $column) {
                if (in_array($column, $fullTextColumns, true)
                    || ($canSearchPrimaryKey && $column === $scoutKey)) {
                    continue;
                }

                $query->orWhere(
                    $model->qualifyColumn($column),
                    'like',
                    in_array($column, $prefixColumns, true) ? $search.'%' : '%'.$search.'%',
                );
            }

            if ($fullTextColumns !== [] && $this->fullTextTerm($builder) !== '') {
                $query->orWhereIn(
                    $model->getQualifiedKeyName(),
                    $this->fullTextMatches($builder, $fullTextColumns)->select($model->getKeyName())
                );
            }
        });
    }

    /**
     * Determine if the query should be ordered by full-text relevance.
     *
     * @param  Builder<Model>  $builder
     */
    protected function shouldOrderByRelevance(Builder $builder): bool
    {
        return $builder->model->getConnection() instanceof MatrixOneConnection
            && count($this->getFullTextColumns($builder)) > 0
            && empty($builder->orders)
            && $this->fullTextTerm($builder) !== '';
    }

    /**
     * Order the query by full-text relevance, most relevant first; rows that
     * only matched a LIKE column come last.
     *
     * MATCH ... AGAINST cannot be evaluated outside a WHERE clause that uses
     * the FULLTEXT index, so the scores come from a joined subquery.
     *
     * @param  Builder<Model>  $builder
     * @param  EloquentBuilder<Model>  $query
     * @return EloquentBuilder<Model>
     */
    protected function orderByRelevance(Builder $builder, $query)
    {
        $model = $builder->model;
        $fullTextColumns = $this->getFullTextColumns($builder);

        $scores = $this->fullTextMatches($builder, $fullTextColumns)
            ->select($model->getKeyName())
            ->selectFullTextRelevance(
                array_map(fn ($column) => $model->qualifyColumn($column), $fullTextColumns),
                $this->fullTextTerm($builder),
                'scout_relevance',
                ['mode' => 'boolean']
            );

        $query->getQuery()->columns ??= [$model->getTable().'.*'];

        $query->leftJoinSub($scores, 'scout_relevance', 'scout_relevance.'.$model->getKeyName(), '=', $model->getQualifiedKeyName())
            ->orderByDesc('scout_relevance.scout_relevance');

        return $query;
    }

    /**
     * A query selecting the rows whose full-text columns match the search.
     *
     * @param  Builder<Model>  $builder
     * @param  array<int, string>  $fullTextColumns
     */
    protected function fullTextMatches(Builder $builder, array $fullTextColumns): QueryBuilder
    {
        $model = $builder->model;

        /** @var QueryBuilder $matches */
        $matches = $model->getConnection()->table($model->getTable());

        return $matches->whereFullText(
            array_map(fn ($column) => $model->qualifyColumn($column), $fullTextColumns),
            $this->fullTextTerm($builder),
            ['mode' => 'boolean']
        );
    }

    /**
     * The boolean-mode full-text query for the search term: the raw term for
     * models declaring ['mode' => 'boolean'], otherwise any of its words.
     *
     * @param  Builder<Model>  $builder
     */
    protected function fullTextTerm(Builder $builder): string
    {
        $term = (string) $builder->query;

        if (($this->getFullTextOptions($builder)['mode'] ?? null) === 'boolean') {
            return trim($term);
        }

        return FullTextQuery::anyOf($term)->toString();
    }
}
