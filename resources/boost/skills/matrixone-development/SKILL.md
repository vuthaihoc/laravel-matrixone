---
name: matrixone-development
description: "Use when an app uses the vuthaihoc/laravel-matrixone driver or a database connection with 'driver' => 'matrixone'. Trigger when designing migrations or schemas, adding indexes, foreign keys or full-text indexes, storing JSON, writing Eloquent or query builder code, implementing search or vector similarity (embeddings, nearest neighbours), configuring MatrixOne session variables, writing tests against MatrixOne, or debugging MatrixOne errors such as 'panic runtime error', 'not supported', case-sensitivity surprises or hanging tests."
license: MIT
metadata:
  author: vuthaihoc
---

# MatrixOne with Laravel

`vuthaihoc/laravel-matrixone` registers the `matrixone` driver on top of Laravel's MySQL stack. Most Laravel code works unchanged; this skill lists what differs and how to design around it. Behaviour was verified on MatrixOne 4.2.4.

## Connection

```php
// config/database.php
'matrixone' => [
    'driver' => 'matrixone',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', 6001),
    'database' => env('DB_DATABASE', 'laravel'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', '111'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'strict' => true,
    // Optional:
    'variables' => [],               // SET SESSION on every connect
    'ignore_json_defaults' => false, // drop JSON defaults instead of throwing
    'ignore_json_indexes' => false,  // skip JSON indexes instead of throwing
    'emulate_prepares' => true,      // keep true unless you have a reason
],
```

The database must exist before migrating (`create database laravel;`).

## Schema design rules

| Need | Do | Avoid |
|------|----|-------|
| Full-text search | `$table->fullText([...])` on a table **without its own foreign keys** | FULLTEXT + foreign key on one table: inserts panic on 4.2.4 |
| Search inside JSON | `$table->fullText('meta')->parser('json')` | Indexing the JSON column directly |
| CJK / partial words | `->parser('ngram')` | Default parser for Chinese/Japanese |
| JSON default | Model `$attributes = ['meta' => '{}']` | `->default('{}')` on JSON (throws) |
| Query by a JSON field | Copy the field into a regular indexed column | Index on JSON or expression index (rejected) |
| Unique e-mail / username | `Lowercase` cast + unique index on the normalized value | Relying on `_ci` collation (ignored) |
| UUID keys | `$table->uuid()` (stored as `char(36)`) | Native `uuid` column type (unreadable by PHP's mysqlnd) |
| Year | `$table->year()` (becomes `smallint`) | |
| Embeddings | `$table->vector('embedding', 1536)` + `$table->vectorIndex('embedding')->lists(100)` (IVF-Flat) | Vector column in a primary/unique key; `->hnsw()` on tables using `id()` (HNSW needs a signed BIGINT key and syncs asynchronously) |
| Long TEXT lookup | FULLTEXT, or `rawIndex('body(100)', 'name')` | `index()` on TEXT (rejected) |
| Computed values | Fill a normal column in a model event/observer | `virtualAs()` / `storedAs()` (throws) |

Other schema facts:
- Migrations type-hint Laravel's `Illuminate\Database\Schema\Blueprint`; `vector64()` is a macro added by the driver.
- Index names longer than 64 characters are shortened automatically (prefix + hash); `hasIndex()`/`dropIndex()` accept the original name.
- `id()->from(1000)` only works when creating the table.
- `renameIndex()` works (drop + re-create). Foreign keys, `change()`, `renameColumn()`, comments work.
- `set()`, `geometry()`, `geography()` columns throw.

Example of a searchable table that respects the FULLTEXT rule:

```php
Schema::create('articles', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('author_id')->index(); // no ->constrained(): the table has a FULLTEXT index
    $table->string('title');
    $table->text('body');
    $table->fullText(['title', 'body']);
    $table->timestamps();
});
```

Enforce the relationship in application code (validation, `exists` rule, observers) when the foreign key has to be dropped.

## Queries

- `where('email', $value)` is case-sensitive. Prefer storing normalized values with the `MatrixOne\Eloquent\Casts\Lowercase` cast and querying `where('email', Str::lower($value))` (uses the index; also lower-case credentials before `Auth::attempt()`). Otherwise use `whereIgnoreCase()` / `whereInIgnoreCase()` (table scan).
- `like` / `whereLike()` are case-insensitive (ILIKE); `whereLike(..., caseSensitive: true)` or the `like binary` operator for exact case.
- All Laravel JSON methods work: `where('meta->a', ...)`, `whereJsonContains()`, `whereJsonOverlaps()`, `whereJsonLength()`, `whereJsonContainsKey()`, `update(['meta->a' => ...])`. `pluck('meta->a')` needs an alias: `pluck('meta->a as a')`.
- `upsert()`, `insertOrIgnore()`, `updateOrCreate()`, `createOrFirst()` work.
- `sharedLock()` becomes `FOR UPDATE`; `inRandomOrder($seed)` ignores the seed; `joinLateral()` throws.

Full-text:

```php
Article::whereFullText(['title', 'body'], 'vector database')->get();
Article::whereFullText('body', '+matrixone -mysql', ['mode' => 'boolean'])->get();
Article::searchFullText(['title', 'body'], $term)->limit(20)->get();             // filter + order by relevance
Article::select('id')->selectFullTextRelevance(['title', 'body'], $term, as: 'score')->get();
```

MatrixOne's natural language mode only matches words appearing together (MySQL matches any word): for search boxes use `FullTextQuery::anyOf($input)`. Build boolean queries with `MatrixOne\Support\FullTextQuery` (`must()`, `mustNot()`, `encourage()`, `discourage()`, `phrase()`, `prefix()`) and `whereFullTextQuery()` / `searchFullText()`; a phrase only works as the whole query. Set `'variables' => ['ft_relevancy_algorithm' => 'BM25']` for better ranking.

Query expansion (`['expanded' => true]`) throws. The columns must match one FULLTEXT index. MatrixOne cannot OR a `MATCH ... AGAINST` with other conditions: write `->orWhereIn('id', DB::table('t')->select('id')->whereFullText(...))` instead of `->orWhereFullText(...)` next to other wheres.

Laravel Scout: use `SCOUT_DRIVER=matrixone` (not `database`) or `matrixone-index`; see the Laravel Scout section below.

Vectors:

```php
protected function casts(): array
{
    return ['embedding' => \MatrixOne\Eloquent\Casts\AsVector::class];
}

Document::nearestTo('embedding', $vector, 10)->get();                 // cosine
Document::nearestTo('embedding', $vector, 10, metric: 'l2')->get();
Document::whereVectorSimilarTo('embedding', $vector, minSimilarity: 0.8)->get();
Document::whereVectorDistanceUsing('cosine', 'embedding', $vector, '<', 0.3)->get();
```

## Session variables

```php
// Always, including after reconnects: 'variables' in config/database.php
'variables' => ['ft_relevancy_algorithm' => 'BM25'],

// For one block, previous values restored afterwards:
DB::connection('matrixone')->withSessionVariables(
    ['ft_relevancy_algorithm' => 'BM25'],
    fn ($db) => Article::searchFullText('body', $term)->get(),
);

// FULLTEXT2 (experimental, asynchronous) and HNSW need their flag when created:
DB::connection('matrixone')->withSessionVariables(['experimental_fulltext2_index' => 1],
    fn ($db) => $db->statement('create fulltext2 index articles_body_ft2 on articles (body)'));
```

Useful variables: `ft_relevancy_algorithm` (`TF-IDF`/`BM25`, typos are accepted silently), `fulltext_bloom_filter_pushdown`, `experimental_fulltext2_index`, `experimental_hnsw_index`. `ngram_token_size` is global only.

## Laravel Scout

- `SCOUT_DRIVER=matrixone`: models stored in MatrixOne; full-text columns, relevance ordering, `->semantic()` and `->hybrid()` on a vector column. Do not use Scout's `database` engine for models with full-text columns.
- `SCOUT_DRIVER=matrixone-index`: models stored in any database, MatrixOne used as a separate search index. Configure `config('scout.matrixone-index')`: `connection` and `index-settings` per model (`fulltext`, `filterable`, `sortable` with types, `parser`, `fold_accents`, `prefix`, `mode`, `embedding`). Every attribute used in `where`/`whereIn`/`orderBy` must be declared as filterable or sortable.
- Full-text has no language support: no stemming (`learn` ≠ `learning`), no stopwords, accents significant (`tieng` ≠ `tiếng`). Use `prefix`, `fold_accents` / `TextNormalizer::foldAccents()` and the `ngram` parser for CJK.

### Embeddings (semantic and hybrid search)

Both engines read the vector from `toSearchableEmbedding()` on the model:

```php
// A string is embedded with the Laravel AI SDK (laravel/ai), cached, one API call per indexed batch.
public function toSearchableEmbedding(): string
{
    return $this->title."\n".$this->body;
}

// An array is stored as is (vectors computed elsewhere); laravel/ai is then not needed for indexing.
public function toSearchableEmbedding(): array
{
    return $this->precomputed_vector;
}
```

- `matrixone`: the model's table needs `$table->vector('embedding', 1536)` (or the column named by `searchableEmbeddingColumn()`) with the `AsVector` cast.
- `matrixone-index`: set `'embedding' => 1536` in the model's `index-settings`; the index table gets the vector column. Without it `->semantic()` / `->hybrid()` throw a `ScoutException`.
- The dimensions must match the embedding model exactly (e.g. 1536 for `text-embedding-3-small`); changing the model means `scout:delete-index` + `scout:import`.
- An empty string or a missing `toSearchableEmbedding()` stores NULL: the record is still found by text search but never by semantic search.
- Search terms are embedded the same way, so `->semantic()` / `->hybrid()` need `laravel/ai` configured even when documents store arrays.

```php
Article::search('how to store songs')->semantic()->get();             // cosine distance <= 1 - 0.6
Article::search('how to store songs')->semantic(minSimilarity: 0.8)->get();
Article::search('songs')->where('status', 'published')->hybrid()->get(); // filters still apply
Article::search('songs')->hybrid(textWeight: 1, semanticWeight: 2)->get();
```

- Hybrid search merges the full-text ranking and the semantic ranking with reciprocal rank fusion (`weight / (60 + rank)`): records found by both come first.
- `orderBy()` cannot be combined with `->semantic()` / `->hybrid()` (throws); order comes from similarity.
- `matrixone-index` keeps at most 1000 candidates per ranking, so `paginate()->total()` is capped at 1000 for semantic and hybrid searches.
- `matrixone-index` creates no vector index on the index table: semantic search scans every row. Fine for tens of thousands of documents; for more, add an IVF-Flat index to the index table yourself after `scout:index`: `Schema::connection('matrixone_search')->table('articles', fn (Blueprint $table) => $table->vectorIndex('embedding')->lists(100));`.
- In tests, extend the engine and override `generateEmbeddings(array $inputs): array` to return fixed vectors instead of calling an AI provider.

## Laravel Pulse and Telescope

- Pulse: publish the package's migration (`php artisan vendor:publish --tag=matrixone-pulse-migrations`) instead of Pulse's; the MatrixOne storage is bound automatically.
- Telescope works unchanged.

## Cache, queue and sessions

`CACHE_STORE=database`, `QUEUE_CONNECTION=database` and `SESSION_DRIVER=database` work with the skeleton tables. There is no `SKIP LOCKED`: concurrent queue workers serialize when popping jobs, so prefer a few workers per queue (or Redis for high-throughput queues).

## Transactions and tests

- Transactions work; savepoints do not. Nested `DB::transaction()` calls are flattened: an inner rollback does not undo the inner writes, only the outermost rollback does. Do not design code that relies on partial rollbacks.
- Laravel's `RefreshDatabase`, `DatabaseTransactions`, `DatabaseTruncation` and `DatabaseMigrations` work unchanged. Because of flattening, a failing inner transaction inside a `RefreshDatabase` test keeps its writes until the test ends.
- Truncation of tables referenced by foreign keys falls back to `DELETE` automatically.

## Known server bugs (4.2.4) and how to avoid them

| Symptom | Trigger | Avoid |
|---------|---------|-------|
| `panic runtime error: invalid memory address` on INSERT | Table with a foreign key **and** a FULLTEXT index | Separate the FULLTEXT table from foreign keys |
| `Error reading result set's header`, then the next queries fail or a test hangs | `loadCount()` / `withCount()` (also `withSum()`-style aggregates using `count(*)`) on **one** parent selected by primary key (`find($id)`, `where('id', $id)`, `whereIn('id', [$id])`), when the related query has an extra condition such as SoftDeletes | Count separately for a single model: `$user->posts()->count()`. `withCount()` over several parents and `withExists()` are fine |
| Wrong IDs from raw `LAST_INSERT_ID()` | Tables with a FULLTEXT index | Use `insertGetId()` / Eloquent (the driver uses `RETURNING`) |

| **Silently wrong results** (`NULL` values) | A correlated scalar subquery with `limit`, e.g. `addSelect(['last' => Post::select('title')->whereColumn('user_id', 'users.id')->latest()->limit(1)])` | Use `hasOne(...)->latestOfMany()` / `ofMany()` relationships or aggregate subqueries (`max()`) |
| `aggregate function sum, bad value [VARCHAR]` | `null as col` placeholders in a `UNION` | `cast(null as double) as col` |

After a server panic the driver drops the broken connection (and forgets its transaction) so later queries reconnect instead of hanging.

## When something fails

1. Read the SQL in the `QueryException`; MatrixOne errors mentioning `not supported` or `panic` are server limitations, not Laravel bugs.
2. Check the package docs page `compatibility.md` in `vendor/vuthaihoc/laravel-matrixone/docs/docs/` for the list of handled differences.
3. The package's `tests/KnownIssues` suite (`composer test:known-issues`) reproduces every open server issue in plain SQL; run it against a new MatrixOne release to see what got fixed.
