# Integrations

- [Laravel Scout](#laravel-scout)
  - [MatrixOne as a separate search index](#matrixone-as-a-separate-search-index)
- [Laravel Pulse](#laravel-pulse)
- [Laravel Telescope](#laravel-telescope)

## Laravel Scout

Use the package's engine: `SCOUT_DRIVER=matrixone`. It supports `LIKE`, prefix and full-text columns, relevance ordering, and semantic/hybrid search on vector columns. Scout's own `database` engine only works for models without full-text columns. See [Full-text Search › Laravel Scout](./full-text#laravel-scout).

### MatrixOne as a separate search index

The `matrixone-index` engine uses a MatrixOne database the way Scout uses Meilisearch or Algolia: your models stay in any database (MySQL, PostgreSQL, SQLite, CockroachDB...) and MatrixOne holds one index table per searchable model.

```dotenv
SCOUT_DRIVER=matrixone-index
```

```php
// config/database.php: a matrixone connection for the index
'matrixone_search' => [
    'driver' => 'matrixone',
    'host' => env('MO_SEARCH_HOST', '127.0.0.1'),
    'port' => env('MO_SEARCH_PORT', 6001),
    'database' => env('MO_SEARCH_DATABASE', 'search'),
    'username' => env('MO_SEARCH_USERNAME', 'root'),
    'password' => env('MO_SEARCH_PASSWORD', ''),
    'variables' => ['ft_relevancy_algorithm' => 'BM25'],
],

// config/scout.php
'matrixone-index' => [
    'connection' => 'matrixone_search',
    'index-settings' => [
        App\Models\Article::class => [
            'fulltext' => ['title', 'body'],          // default: every attribute of toSearchableArray()
            'filterable' => ['status', 'author_id' => 'integer'],
            'sortable' => ['published_at' => 'datetime', 'views' => 'integer'],
            'parser' => 'ngram',                      // optional: 'ngram' (CJK) or 'json'
            'fold_accents' => true,                   // "tieng viet" matches "Tiếng Việt" (needs ext-intl)
            'prefix' => true,                         // "learn" matches "learning"
            'mode' => 'natural',                      // 'boolean' passes user operators through
            'embedding' => 1536,                      // enables ->semantic() and ->hybrid()
        ],
    ],
],
```

Models only need Scout's `Searchable` trait. The index table is created on the first import (or with `php artisan scout:index articles`) and holds:

| Column | Content |
|--------|---------|
| `scout_key` | the model's Scout key (primary key) |
| `content` | the `fulltext` attributes joined, with a FULLTEXT index |
| `document` | the whole searchable array as JSON |
| `attr_<name>` | each filterable/sortable attribute, typed (`string`, `integer`, `float`, `boolean`, `datetime`) and indexed |
| `attr___soft_deleted` | Scout's soft delete flag (`scout.soft_delete`) |
| `embedding` | the vector, when `embedding` is set |

```php
Article::search('database search')->get();                                  // any word, by relevance
Article::search('laravel')->where('status', 'published')->orderBy('views', 'desc')->paginate(20);
Article::search('laravel')->whereIn('author_id', [1, 2])->get();
Article::search('laravel')->where('views', '>', 100)->get();
Article::search('how to store songs')->semantic()->get();                   // cosine similarity
Article::search('songs')->hybrid()->get();                                  // reciprocal rank fusion
```

- `where`, `whereIn`, `whereNotIn` and `orderBy` only accept attributes listed as `filterable` or `sortable`; others throw an `InvalidArgumentException`, like Meilisearch's filterable attributes.
- Scout's `import`, `flush`, `scout:index`, `scout:delete-index`, `withTrashed()`/`onlyTrashed()`, `query()` callbacks and `keys()` work as with other engines.
- Embeddings come from `toSearchableEmbedding()`: an array is stored as is, a string is embedded with the [Laravel AI SDK](https://github.com/laravel/ai). Search terms are embedded the same way.
- Index tables have no foreign keys, so FULLTEXT inserts are safe on MatrixOne 4.2.4.

## Laravel Pulse

Pulse's database storage only supports the `mysql`, `mariadb`, `pgsql` and `sqlite` drivers, and its MySQL schema uses a generated column that MatrixOne does not support. The package provides a MatrixOne storage (bound automatically when Pulse is installed) and a MatrixOne version of Pulse's migration.

1. Install Pulse without its migration:

   ```bash
   composer require laravel/pulse
   php artisan vendor:publish --provider="Laravel\Pulse\PulseServiceProvider" --tag=pulse-config
   ```

2. Publish the MatrixOne migration instead of Pulse's (same file name, `create_pulse_tables`):

   ```bash
   php artisan vendor:publish --tag=matrixone-pulse-migrations
   php artisan migrate
   ```

   If Pulse's own migration was already published, delete it first; it throws `Pulse does not support the [matrixone] database driver`.

3. Keep `PULSE_STORAGE_DRIVER=database`; `pulse.storage.database.connection` may point to a MatrixOne connection.

What the storage changes:

- keys are hashed in PHP (`key_hash` is a plain column);
- aggregates are upserted with MySQL's `values()` syntax;
- dashboard queries run inside `MatrixOneConnection::withCompatibilityRewrites()`, which types the `null` placeholders of Pulse's `UNION` queries and rewrites its correlated `limit 1` key lookup, working around two MatrixOne bugs (see [Compatibility](./compatibility#known-matrixone-issues)).

Covered by tests: recording and ingesting entries, counts/sums/min/max/averages over repeated upserts, values, `aggregate()`, `aggregateTypes()`, `aggregateTotal()`, `graph()`, trimming and purging.

## Laravel Telescope

Telescope's database storage works unchanged with its own migration: storing entries and tags, updates, grouped exceptions, monitored tags, filtering by tag, family hash and batch, pruning and clearing are covered by tests.

The only difference: `telescope:prune` reports a larger number of deleted rows, because MatrixOne counts the tags removed by `ON DELETE CASCADE` in a `DELETE`'s affected rows.
