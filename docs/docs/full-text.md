# Full-text Search

- [Creating indexes](#creating-indexes)
- [Querying](#querying)
- [Relevance](#relevance)
- [Session variables](#session-variables)
- [FULLTEXT2 (experimental)](#fulltext2-experimental)
- [Limitations](#limitations)

MatrixOne ships a MySQL-compatible `FULLTEXT` index. It is maintained synchronously: rows are searchable right after `INSERT`, `UPDATE`, `DELETE` or an upsert.

## Creating indexes

```php
use Illuminate\Database\Schema\Blueprint;

Schema::create('articles', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('body');
    $table->json('meta');

    $table->fullText('title');                        // default parser
    $table->fullText(['title', 'body']);              // several columns
    $table->fullText('body', 'articles_body_ngram')->parser('ngram');
    $table->fullText('meta')->parser('json');         // search inside JSON values
});
```

| Parser | Use it for |
|--------|-----------|
| default | Space-separated languages (English, Vietnamese...) |
| `ngram` | Languages without spaces (Chinese, Japanese) and partial words. Tokens are `ngram_token_size` characters long (3 by default, a global server variable). |
| `json` | JSON columns: every string value inside the document becomes searchable. |

Drop an index with `$table->dropFullText(['title'])` or by name.

## Querying

```php
Article::whereFullText('title', 'matrixone')->get();
Article::whereFullText(['title', 'body'], 'vector database')->get();
Article::whereFullText(['title', 'body'], '+vector -slow', ['mode' => 'boolean'])->get();
Article::whereFullText('meta', 'red')->get();   // JSON parser index
```

The columns passed to `whereFullText()` must match the columns of one FULLTEXT index. The natural language and boolean modes are supported. Query expansion (`['expanded' => true]`) is not supported by MatrixOne and throws a `RuntimeException`.

## Relevance

The driver adds relevance helpers that Laravel lacks:

```php
// Filter and sort by relevance in one call.
Article::searchFullText(['title', 'body'], 'vector database')->limit(20)->get();

// Expose the score.
Article::query()
    ->select('id', 'title')
    ->selectFullTextRelevance(['title', 'body'], 'vector database', as: 'score')
    ->whereFullText(['title', 'body'], 'vector database')
    ->orderByFullTextRelevance(['title', 'body'], 'vector database')
    ->get();
```

Every helper accepts `['mode' => 'boolean']`; `orderByFullTextRelevance()` also takes a direction (`'desc'` by default).

## Session variables

A few server variables tune full-text search. They can be set in three ways.

**For every connection**, in `config/database.php`. They are applied with `SET SESSION` on connect and after every reconnect:

```php
'matrixone' => [
    'driver' => 'matrixone',
    // ...
    'variables' => [
        'ft_relevancy_algorithm' => 'BM25',
        'fulltext_bloom_filter_pushdown' => true,
    ],
],
```

**For one block of code**, restoring the previous values afterwards (even if the callback throws):

```php
use MatrixOne\MatrixOneConnection;

$results = DB::connection('matrixone')->withSessionVariables(
    ['ft_relevancy_algorithm' => 'BM25'],
    fn (MatrixOneConnection $db) => Article::searchFullText('body', 'database')->limit(10)->get(),
);
```

**Until the connection closes:**

```php
DB::connection('matrixone')->setSessionVariables(['fulltext_bloom_filter_pushdown' => 1]);

DB::connection('matrixone')->getSessionVariables(['ft_relevancy_algorithm']);
// ['ft_relevancy_algorithm' => 'TF-IDF']
```

| Variable | Default | Effect |
|----------|---------|--------|
| `ft_relevancy_algorithm` | `TF-IDF` | Scoring of `FULLTEXT` indexes: `TF-IDF` or `BM25`. Invalid values are accepted silently, so double-check the spelling. |
| `ft2_relevancy_algorithm` | `BM25` | Scoring of `FULLTEXT2` indexes. |
| `fulltext_bloom_filter_pushdown` | `0` | Lets the planner push full-text predicates into bloom filters. Try it on large tables and compare query plans. |
| `experimental_fulltext2_index` | `0` | Required to create `FULLTEXT2` indexes (see below). |
| `ngram_token_size` | `3` | Token length of the `ngram` parser. **Global only**: `SET GLOBAL ngram_token_size = 2` by an administrator, before creating the index. |

Variable names are validated (letters, digits and underscores) and values are quoted, so they are safe to take from configuration.

## FULLTEXT2 (experimental)

MatrixOne 4.2.2+ has a second full-text engine, `FULLTEXT2`. It is behind a session flag, and its index is maintained asynchronously: new rows may not be searchable immediately. There is no Blueprint method for it; create it with raw SQL inside `withSessionVariables()`:

```php
use MatrixOne\MatrixOneConnection;

public function up(): void
{
    DB::connection('matrixone')->withSessionVariables(
        ['experimental_fulltext2_index' => 1],
        fn (MatrixOneConnection $db) => $db->statement('create fulltext2 index articles_body_ft2 on articles (body)'),
    );
}

public function down(): void
{
    Schema::connection('matrixone')->table('articles', fn ($table) => $table->dropIndex('articles_body_ft2'));
}
```

Force the index to catch up, for example after a bulk import:

```php
DB::connection('matrixone')->statement('alter table articles alter reindex articles_body_ft2 fulltext2 force_sync');
```

Queries use the same `whereFullText()` / relevance helpers. Prefer the classic `FULLTEXT` index unless you need FULLTEXT2's features.

## Laravel Scout

The package registers a Scout engine for MatrixOne. Use it instead of Scout's `database` engine:

```dotenv
SCOUT_DRIVER=matrixone
```

It behaves like Scout's database engine (`LIKE` columns, `#[SearchUsingPrefix]`, `#[SearchUsingFullText]`, `where`/`whereIn`, pagination, soft deletes) with three differences:

- Full-text matches are selected through a subquery. MatrixOne cannot combine `MATCH ... AGAINST` with the `LIKE` conditions by `OR`, so Scout's own `database` engine fails on models that mix full-text and `LIKE` columns (it works for `LIKE`-only models).
- Results are ordered by full-text relevance, as Scout does on PostgreSQL.
- Semantic and hybrid search, which Scout enables on PostgreSQL only, work on MatrixOne vector columns:

```php
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Searchable;
use MatrixOne\Eloquent\Casts\AsVector;

class Article extends Model
{
    use Searchable;

    protected function casts(): array
    {
        return ['embedding' => AsVector::class];
    }

    #[SearchUsingFullText(['title', 'body'])]
    public function toSearchableArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'body' => $this->body];
    }

    // A string is embedded with the Laravel AI SDK; an array is stored as is.
    public function toSearchableEmbedding(): string|array
    {
        return $this->title."\n".$this->body;
    }
}

Article::search('vector database')->get();              // LIKE + full-text, by relevance
Article::search('how to store songs')->semantic()->get(); // cosine similarity
Article::search('songs')->hybrid()->get();                // rank fusion of both
```

The table needs a `vector('embedding', <dimensions>)` column (or the column named by `searchableEmbeddingColumn()`) and, for full-text columns, a FULLTEXT index; keep that table free of foreign keys (MatrixOne 4.2.4). Embeddings of search terms are generated with the [Laravel AI SDK](https://github.com/laravel/ai) (`laravel/ai`).

## Limitations

- **MatrixOne 4.2.4 crash:** inserting into a table that has both its own foreign key and a FULLTEXT index panics in the query planner. Keep full-text indexes on tables without foreign keys (being *referenced* by a foreign key is fine).
- **`LAST_INSERT_ID()` is wrong on FULLTEXT tables.** The driver already reads generated keys with `insert ... returning`; avoid `select last_insert_id()` in raw SQL.
- Query expansion is not supported.
- `MATCH ... AGAINST` cannot be combined with other conditions by `OR` (`->orWhereFullText()` next to other `where`s). Select the matches with a subquery instead: `->orWhereIn('id', DB::table('articles')->select('id')->whereFullText('body', $term))`.
