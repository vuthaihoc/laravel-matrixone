<?php

namespace MatrixOne\Scout;

use DateTimeInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\SupportsSemanticSearch;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Exceptions\ScoutException;
use MatrixOne\MatrixOneConnection;
use MatrixOne\Query\Builder as QueryBuilder;
use MatrixOne\Support\FullTextQuery;
use MatrixOne\Support\TextNormalizer;
use RuntimeException;

/**
 * Scout engine storing search indexes in a (separate) MatrixOne database
 * (SCOUT_DRIVER=matrixone-index), like Meilisearch or Algolia: models live in
 * any database, MatrixOne holds one index table per searchable model and
 * answers full-text, filtered, sorted, semantic and hybrid searches.
 */
class MatrixOneIndexEngine extends Engine implements SupportsSemanticSearch
{
    /** @var array<string, true> */
    protected array $existingIndexes = [];

    /**
     * @param  array<string, mixed>  $config  config('scout.matrixone-index')
     */
    public function __construct(
        protected ConnectionResolverInterface $db,
        protected array $config = [],
    ) {}

    /**
     * The MatrixOne connection holding the indexes.
     */
    public function connection(): MatrixOneConnection
    {
        $name = $this->config['connection'] ?? null;
        $connection = $this->db->connection(is_string($name) ? $name : null);

        if (! $connection instanceof MatrixOneConnection) {
            throw new RuntimeException('The matrixone-index Scout engine needs a connection using the matrixone driver.');
        }

        return $connection;
    }

    /**
     * The settings of the index of the given model (or index name).
     */
    public function settings(Model|string $model): IndexSettings
    {
        /** @var array<string, array<string, mixed>> $all */
        $all = (array) ($this->config['index-settings'] ?? []);

        if ($model instanceof Model) {
            $settings = $all[$model::class] ?? $all[$model->searchableAs()] ?? []; // @phpstan-ignore method.notFound
        } else {
            $settings = $all[$model] ?? [];

            foreach ($all as $key => $candidate) {
                if (class_exists($key) && method_exists($key, 'searchableAs') && (new $key)->searchableAs() === $model) {
                    $settings = $candidate;
                }
            }
        }

        return IndexSettings::fromArray($settings);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Model>  $models
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        /** @var Model $first */
        $first = $models->first();
        $table = $first->indexableAs(); // @phpstan-ignore method.notFound
        $settings = $this->settings($first);

        $this->ensureIndex($table, $settings);

        if ($this->usesSoftDelete($first) && config('scout.soft_delete', false)) {
            $models->each->pushSoftDeleteMetadata(); // @phpstan-ignore method.notFound
        }

        $rows = [];
        $embeddingInputs = [];

        foreach ($models as $model) {
            $document = array_merge($model->toSearchableArray(), $model->scoutMetadata()); // @phpstan-ignore method.notFound, method.notFound

            if ($document === []) {
                continue;
            }

            $key = (string) $model->getScoutKey(); // @phpstan-ignore method.notFound
            $rows[$key] = $this->row($key, $document, $settings);

            if ($settings->embedding !== null && method_exists($model, 'toSearchableEmbedding')) {
                $embeddingInputs[$key] = $model->toSearchableEmbedding();
            }
        }

        foreach ($this->embed($embeddingInputs) as $key => $vector) {
            $rows[$key]['embedding'] = json_encode($vector, JSON_THROW_ON_ERROR);
        }

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            $this->connection()->table($table)->upsert($chunk, ['scout_key'], array_keys($chunk[0]));
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Model>  $models
     */
    public function delete($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        /** @var Model $first */
        $first = $models->first();
        $table = $first->indexableAs(); // @phpstan-ignore method.notFound

        if (! $this->indexExists($table)) {
            return;
        }

        $keys = $models->map(fn ($model) => (string) $model->getScoutKey())->all(); // @phpstan-ignore method.notFound

        $this->connection()->table($table)->whereIn('scout_key', $keys)->delete();
    }

    /**
     * @param  Builder<Model>  $builder
     * @return array{ids: list<string>, total: int}
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, $builder->limit, 0);
    }

    /**
     * @param  Builder<Model>  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return array{ids: list<string>, total: int}
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, (int) $perPage, ((int) $page - 1) * (int) $perPage);
    }

    /**
     * @param  array{ids: list<string>, total: int}  $results
     * @return Collection<int, string>
     */
    public function mapIds($results)
    {
        return collect($results['ids']);
    }

    /**
     * @param  Builder<Model>  $builder
     * @param  array{ids: list<string>, total: int}  $results
     * @param  Model  $model
     * @return \Illuminate\Database\Eloquent\Collection<int, Model>
     */
    public function map(Builder $builder, $results, $model)
    {
        $ids = $results['ids'];

        if ($ids === []) {
            return $model->newCollection();
        }

        $positions = array_flip($ids);

        return $model->getScoutModelsByIds($builder, $ids) // @phpstan-ignore method.notFound
            ->filter(fn ($model) => isset($positions[(string) $model->getScoutKey()]))
            ->sortBy(fn ($model) => $positions[(string) $model->getScoutKey()])
            ->values();
    }

    /**
     * @param  Builder<Model>  $builder
     * @param  array{ids: list<string>, total: int}  $results
     * @param  Model  $model
     * @return LazyCollection<int, Model>
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        $ids = $results['ids'];

        if ($ids === []) {
            return LazyCollection::make();
        }

        $positions = array_flip($ids);

        return $model->queryScoutModelsByIds($builder, $ids) // @phpstan-ignore method.notFound
            ->cursor()
            ->filter(fn ($model) => isset($positions[(string) $model->getScoutKey()]))
            ->sortBy(fn ($model) => $positions[(string) $model->getScoutKey()])
            ->values();
    }

    /**
     * @param  array{ids: list<string>, total: int}  $results
     */
    public function getTotalCount($results)
    {
        return $results['total'];
    }

    /**
     * @param  Model  $model
     */
    public function flush($model)
    {
        $table = $model->indexableAs(); // @phpstan-ignore method.notFound

        if ($this->indexExists($table)) {
            $this->connection()->table($table)->delete();
        }
    }

    /**
     * @param  string  $name
     * @param  array<string, mixed>  $options
     */
    public function createIndex($name, array $options = [])
    {
        $settings = $this->settings($name);
        $schema = $this->connection()->getSchemaBuilder();

        if ($schema->hasTable($name)) {
            return;
        }

        $schema->create($name, function (Blueprint $table) use ($settings) {
            $table->string('scout_key', 191)->primary();
            $table->longText('content')->nullable();
            $table->json('document')->nullable();

            foreach ($settings->attributes as $attribute => $type) {
                $column = 'attr_'.$attribute;

                match ($type) {
                    'integer' => $table->bigInteger($column)->nullable(),
                    'float' => $table->double($column)->nullable(),
                    'boolean' => $table->boolean($column)->nullable(),
                    'datetime' => $table->dateTime($column, 6)->nullable(),
                    default => $table->string($column, 191)->nullable(),
                };

                $table->index($column);
            }

            if ($settings->embedding !== null) {
                $table->vector('embedding', $settings->embedding)->nullable();
            }

            $fullText = $table->fullText('content');

            if ($settings->parser !== null) {
                $fullText->parser($settings->parser); // @phpstan-ignore method.notFound
            }
        });

        $this->existingIndexes[$name] = true;
    }

    /**
     * @param  string  $name
     */
    public function deleteIndex($name)
    {
        $this->connection()->getSchemaBuilder()->dropIfExists($name);

        unset($this->existingIndexes[$name]);
    }

    /**
     * Run a search and return the matching keys, ranked, with the total.
     *
     * @param  Builder<Model>  $builder
     * @return array{ids: list<string>, total: int}
     */
    protected function performSearch(Builder $builder, ?int $limit, int $offset): array
    {
        $table = $builder->index ?: $builder->model->searchableAs(); // @phpstan-ignore method.notFound
        $settings = $this->settings($builder->model);

        if (! $this->indexExists($table)) {
            return ['ids' => [], 'total' => 0];
        }

        if ($builder->semanticSearch || $builder->hybridSearch !== null) {
            return $this->vectorSearch($builder, $table, $settings, $limit, $offset);
        }

        $query = $this->textQuery($builder, $table, $settings);
        $total = (clone $query)->count();

        $ids = $query
            ->when($offset > 0, fn ($query) => $query->offset($offset))
            ->when($limit !== null, fn ($query) => $query->limit((int) $limit))
            ->pluck('scout_key')
            ->map(fn (mixed $key): string => is_scalar($key) ? (string) $key : '')
            ->all();

        return ['ids' => array_values($ids), 'total' => $total];
    }

    /**
     * The filtered, ordered full-text query.
     *
     * @param  Builder<Model>  $builder
     */
    protected function textQuery(Builder $builder, string $table, IndexSettings $settings): QueryBuilder
    {
        $query = $this->filteredQuery($builder, $table, $settings)->select('scout_key');
        $term = $this->term($builder, $settings);

        if ($term !== '') {
            $query->whereFullText('content', $term, ['mode' => 'boolean']);
        }

        if ($builder->callback) {
            $result = call_user_func($builder->callback, $query, $builder->query, $builder->options);
            $query = $result instanceof QueryBuilder ? $result : $query;
        }

        foreach ($builder->orders as $order) {
            $column = $order['column'] === 'scout_key' ? 'scout_key' : $settings->column($order['column']);
            $query->orderBy($column, $order['direction']);
        }

        if ($builder->orders === []) {
            $term !== ''
                ? $query->orderByFullTextRelevance('content', $term, ['mode' => 'boolean'])
                : $query->orderByDesc('scout_key');
        }

        return $query;
    }

    /**
     * Semantic (->semantic()) or hybrid (->hybrid()) search.
     *
     * @param  Builder<Model>  $builder
     * @return array{ids: list<string>, total: int}
     */
    protected function vectorSearch(Builder $builder, string $table, IndexSettings $settings, ?int $limit, int $offset): array
    {
        if ($settings->embedding === null) {
            throw new ScoutException("Semantic search needs an 'embedding' dimension in the [{$table}] MatrixOne index settings.");
        }

        if ($builder->orders !== []) {
            throw new ScoutException('Order clauses cannot be combined with semantic or hybrid search.');
        }

        $vector = $this->embed(['query' => (string) $builder->query])['query'];
        $similarity = $builder->minimumSimilarity ?? 0.6;

        if (! is_numeric($similarity) || $similarity < 0 || $similarity > 1) {
            throw new ScoutException('The minimum similarity must be between 0 and 1.');
        }

        $semantic = $this->filteredQuery($builder, $table, $settings)
            ->whereNotNull('embedding')
            ->whereVectorDistanceUsing('cosine', 'embedding', $vector, '<=', 1 - (float) $similarity)
            ->orderByVectorDistanceUsing('cosine', 'embedding', $vector)
            ->limit(1000)
            ->pluck('scout_key')
            ->map(fn (mixed $key): string => is_scalar($key) ? (string) $key : '')
            ->all();

        if ($builder->semanticSearch) {
            $ranked = $semantic;
        } else {
            $text = $this->textQuery($builder, $table, $settings)
                ->limit(1000)
                ->pluck('scout_key')
                ->map(fn (mixed $key): string => is_scalar($key) ? (string) $key : '')
                ->all();

            /** @var array{text_weight: float|int, semantic_weight: float|int} $weights */
            $weights = $builder->hybridSearch;

            $ranked = $this->fuse([
                [$text, (float) $weights['text_weight']],
                [$semantic, (float) $weights['semantic_weight']],
            ]);
        }

        return [
            'ids' => array_values(array_slice($ranked, $offset, $limit)),
            'total' => count($ranked),
        ];
    }

    /**
     * Weighted reciprocal rank fusion of ranked key lists (as in Scout).
     *
     * @param  list<array{0: list<string>, 1: float}>  $rankings
     * @return list<string>
     */
    protected function fuse(array $rankings): array
    {
        $scores = [];

        foreach ($rankings as [$keys, $weight]) {
            foreach (array_values(array_unique($keys)) as $position => $key) {
                $scores[$key] = ($scores[$key] ?? 0) + $weight / (60 + $position + 1);
            }
        }

        uksort($scores, fn ($left, $right) => $scores[$right] <=> $scores[$left] ?: strcmp((string) $left, (string) $right));

        return array_map('strval', array_keys($scores));
    }

    /**
     * The index query with the builder's where / whereIn / whereNotIn filters.
     *
     * @param  Builder<Model>  $builder
     */
    protected function filteredQuery(Builder $builder, string $table, IndexSettings $settings): QueryBuilder
    {
        /** @var QueryBuilder $query */
        $query = $this->connection()->table($table);

        foreach ($builder->wheres as $where) {
            $query->where($settings->column($where['field']), $where['operator'], $this->value($where['value']));
        }

        foreach ($builder->whereIns as $field => $values) {
            $query->whereIn($settings->column($field), array_map(fn ($value) => $this->value($value), $values));
        }

        foreach ($builder->whereNotIns as $field => $values) {
            $query->whereNotIn($settings->column($field), array_map(fn ($value) => $this->value($value), $values));
        }

        return $query;
    }

    /**
     * The boolean-mode full-text query for the search term.
     *
     * @param  Builder<Model>  $builder
     */
    protected function term(Builder $builder, IndexSettings $settings): string
    {
        $term = trim((string) $builder->query);

        if ($settings->foldAccents) {
            $term = TextNormalizer::foldAccents($term);
        }

        return $settings->mode === 'boolean'
            ? $term
            : FullTextQuery::anyOf($term, $settings->prefix)->toString();
    }

    /**
     * Build the index row of a document.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    protected function row(string $key, array $document, IndexSettings $settings): array
    {
        $fields = $settings->fulltext ?? array_keys($document);

        $content = collect($fields)
            ->map(fn ($field) => $this->text($document[$field] ?? null))
            ->filter(fn ($text) => $text !== '')
            ->implode("\n");

        $row = [
            'scout_key' => $key,
            'content' => $settings->foldAccents ? TextNormalizer::foldAccents($content) : $content,
            'document' => json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ];

        foreach ($settings->attributes as $attribute => $type) {
            $value = $document[$attribute] ?? ($attribute === '__soft_deleted' ? 0 : null);
            $row['attr_'.$attribute] = $this->value($value);
        }

        return $row;
    }

    /**
     * Flatten a document value into searchable text.
     */
    protected function text(mixed $value): string
    {
        return match (true) {
            is_array($value) => collect($value)->flatten()->map(fn ($item) => $this->text($item))->filter()->implode(' '),
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /**
     * Normalize a value for an attribute column or filter.
     */
    protected function value(mixed $value): mixed
    {
        return match (true) {
            is_bool($value) => (int) $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s.u'),
            is_array($value) => json_encode($value, JSON_THROW_ON_ERROR),
            default => $value,
        };
    }

    /**
     * Resolve embeddings: arrays are used as they are, strings are embedded
     * with the Laravel AI SDK.
     *
     * @param  array<string, mixed>  $inputs
     * @return array<string, array<int, float>>
     */
    protected function embed(array $inputs): array
    {
        $vectors = [];
        $texts = [];

        foreach ($inputs as $key => $input) {
            if (is_array($input)) {
                $vectors[$key] = array_map('floatval', array_values($input));
            } elseif (is_string($input) && trim($input) !== '') {
                $texts[$key] = $input;
            }
        }

        if ($texts !== []) {
            $generated = $this->generateEmbeddings(array_values($texts));

            foreach (array_keys($texts) as $position => $key) {
                $vectors[$key] = array_map('floatval', array_values($generated[$position]));
            }
        }

        return $vectors;
    }

    /**
     * Generate embeddings with the Laravel AI SDK.
     *
     * @param  list<string>  $inputs
     * @return list<array<int, float>>
     */
    protected function generateEmbeddings(array $inputs): array
    {
        $embeddings = 'Laravel\\Ai\\Embeddings';

        if (! class_exists($embeddings)) {
            throw new ScoutException('Embedding text requires the Laravel AI SDK. Please install the [laravel/ai] package.');
        }

        $result = $embeddings::for($inputs)->cache()->generate()->embeddings; // @phpstan-ignore staticMethod.notFound

        if (! is_array($result) || count($result) !== count($inputs)) {
            throw new ScoutException('Laravel AI returned an unexpected number of embeddings.');
        }

        /** @var list<array<int, float>> */
        return array_values($result);
    }

    /**
     * Create the index table when it does not exist yet.
     */
    protected function ensureIndex(string $table, IndexSettings $settings): void
    {
        if (! $this->indexExists($table)) {
            $this->createIndex($table);
        }
    }

    protected function usesSoftDelete(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    protected function indexExists(string $table): bool
    {
        if (isset($this->existingIndexes[$table])) {
            return true;
        }

        if ($this->connection()->getSchemaBuilder()->hasTable($table)) {
            return $this->existingIndexes[$table] = true;
        }

        return false;
    }
}
