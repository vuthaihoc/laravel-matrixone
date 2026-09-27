---
layout: home

hero:
  name: Laravel MatrixOne
  text: MatrixOne Driver for Laravel
  tagline: Use MatrixOne as a drop-in Laravel database — Eloquent, Query Builder, Schema Builder, migrations and transactions.
  actions:
    - theme: brand
      text: Get Started
      link: /docs/installation
    - theme: alt
      text: View on GitHub
      link: https://github.com/vuthaihoc/laravel-matrixone

features:
  - title: Drop-in driver
    details: A `matrixone` driver built on Laravel's MySQL stack. Read/write splitting, reconnects and lazy connections work like any built-in driver.
  - title: Eloquent & Query Builder
    details: Relationships, eager loading, soft deletes, upserts, JSON columns, full-text search and pagination on MatrixOne.
  - title: Schema & Migrations
    details: Standard `artisan migrate`, `migrate:fresh` and `db:wipe`, with schema introspection adapted to MatrixOne's catalog.
  - title: Real transactions
    details: MatrixOne is ACID, so Laravel's own RefreshDatabase and DatabaseTransactions testing traits work unchanged.
  - title: Full-text search
    details: FULLTEXT indexes with relevance ranking in the query builder, and two Laravel Scout drivers — search inside your MatrixOne tables, or use MatrixOne as a separate search index.
    link: /docs/full-text
  - title: Vector search
    details: vecf32 / vecf64 columns, IVF-Flat and HNSW indexes, an AsVector cast and nearest-neighbour queries.
  - title: Companion packages
    details: Works with laravel-db-portable (portable JSON, NULLS LAST and index macros, scan / audit / copy for switching databases) and the CockroachDB driver vuthaihoc/cockroachdb-laravel.
    link: '#companion-packages'
  - title: MatrixOne-aware
    details: Works around MatrixOne quirks (boolean results, savepoints, TRUNCATE with foreign keys) and fails clearly on unsupported features.
---

## Full-text search, two ways

MatrixOne has native FULLTEXT indexes (BM25 or TF-IDF ranking, an `ngram` parser for Chinese, Japanese and Korean). The package exposes them in two ways.

### 1. Built in: query builder and Eloquent

For models stored in MatrixOne. Create the index in a migration and query it like any Laravel full-text search, with relevance ranking:

```php
Schema::create('articles', function (Blueprint $table) {
    $table->id();                       // MatrixOne needs a primary key for FULLTEXT
    $table->string('title');
    $table->text('body');
    $table->fullText(['title', 'body'])->parser('ngram');   // parser optional
});

Article::whereFullText(['title', 'body'], 'vector database')->get();
Article::searchFullText(['title', 'body'], $term)->limit(20)->get();      // filter + order by relevance
Article::select('id')->selectFullTextRelevance(['title', 'body'], $term, as: 'score')->get();

// Boolean queries: +must -exclude "phrases" prefix*
use MatrixOne\Support\FullTextQuery;

Article::searchFullText(['title', 'body'],
    FullTextQuery::make()->must('laravel')->mustNot('legacy')->prefix('match')
)->get();
Article::searchFullText('body', FullTextQuery::anyOf($userInput))->get();   // any word, like a search box
```

[Full-text Search guide →](/docs/full-text)

### 2. Laravel Scout drivers

**`SCOUT_DRIVER=matrixone`** searches the model's own MatrixOne table: full-text and `LIKE` columns, relevance ordering, and semantic or hybrid search on a vector column.

```php
#[SearchUsingFullText(['title', 'body'])]
public function toSearchableArray(): array { /* ... */ }

Article::search('vector database')->get();               // full-text, by relevance
Article::search('how to store songs')->semantic()->get(); // cosine similarity
Article::search('songs')->hybrid()->get();                // rank fusion of both
```

**`SCOUT_DRIVER=matrixone-index`** uses a separate MatrixOne database as the search index, like Meilisearch or Algolia. Your models can stay in MySQL, PostgreSQL, CockroachDB or SQLite:

```php
// config/scout.php
'matrixone-index' => [
    'connection' => 'matrixone_search',
    'index-settings' => [
        Article::class => [
            'fulltext' => ['title', 'body'],
            'filterable' => ['status', 'author_id' => 'integer'],
            'sortable' => ['published_at' => 'datetime'],
            'fold_accents' => true,   // "tieng viet" matches "Tiếng Việt"
            'prefix' => true,         // "learn" matches "learning"
            'embedding' => 1536,      // enables ->semantic() and ->hybrid()
        ],
    ],
],

Article::search('laravel')->where('status', 'published')->orderBy('published_at', 'desc')->paginate(20);
```

[Scout integration guide →](/docs/integrations#laravel-scout)

## Companion packages

Packages from the same author, tested together with this driver:

| Package | Use it with MatrixOne to |
|---------|--------------------------|
| [vuthaihoc/laravel-db-portable](https://github.com/vuthaihoc/laravel-db-portable) | Write queries and migrations that run on MatrixOne, MySQL, PostgreSQL/CockroachDB and SQLite (`whereJsonNumber()`, `sumJson()`, `orderByNullsLast()`, `incrementJson()`, `jsonKeyIndex()`, `trigramIndex()`, `forDriver()`…), and move an application to MatrixOne with `db-portable:scan`, `db-portable:audit` and `db-portable:copy`. |
| [vuthaihoc/cockroachdb-laravel](https://github.com/vuthaihoc/crdb2025) | Run the other side of a move from CockroachDB. Its `strict_integers` option keeps integer columns within MySQL ranges, so the data fits MatrixOne's schema. |

```bash
composer require vuthaihoc/laravel-matrixone:^1.0@beta
composer require vuthaihoc/laravel-db-portable:^0.3          # optional
```

A typical move from CockroachDB to MatrixOne:

```bash
php artisan db-portable:scan --target=matrixone             # SQL that MatrixOne will reject
php artisan migrate --database=matrixone
php artisan db-portable:audit --from=crdb --to=matrixone     # values that do not fit the new schema
php artisan db-portable:copy --from=crdb --to=matrixone
```

The Laravel packages that use the database also work: Scout (two drivers, above), Pulse (with the package's migration), Telescope, and the database cache, queue and session drivers. See [Integrations](/docs/integrations).

