# Query Builder

- [Overview](#overview)
- [Behaviour differences](#behaviour-differences)
- [JSON columns](#json-columns)
- [Full-text search](#full-text-search)
- [Vector search](#vector-search)

## Overview

`DB::connection('matrixone')->table(...)` returns `MatrixOne\Query\Builder`, a subclass of Laravel's query builder. Everything that works on MySQL works the same way unless listed below: selects, joins, sub-queries, unions, CTEs, window functions, aggregates, pagination, chunking, cursors, `insertGetId`, `insertOrIgnore`, `upsert`, `updateOrInsert`, joined updates and deletes, and `lockForUpdate()`.

## Behaviour differences

| Feature | Behaviour on MatrixOne |
|---------|------------------------|
| `sharedLock()` | Compiled to `for update` — MatrixOne has no shared row locks. |
| `inRandomOrder($seed)` | The seed is ignored; MatrixOne's `RAND()` takes no argument. |
| `upsert()` | Uses `on duplicate key update col = values(col)` (the MySQL 8 row alias is not supported). |
| `delete()` without conditions | Compiled with `where 1 = 1` to avoid a MatrixOne bug that corrupts foreign key metadata. |
| `truncate()` | Falls back to `DELETE` when the table is referenced by a foreign key (MatrixOne refuses to truncate it). |
| `joinLateral()` | Throws — lateral joins are not supported. |
| `exists()` | Works; the driver maps MatrixOne's `"true"`/`"false"` strings to real booleans. |
| `insertGetId()` / Eloquent `create()` | Uses `insert ... returning <key>`, because `LAST_INSERT_ID()` is wrong on tables with a FULLTEXT index. |

::: warning Raw boolean expressions
MatrixOne returns boolean expressions such as `select a = b` as the strings `"true"` and `"false"`. In PHP `(bool) "false"` is `true`. The driver handles this for its own queries; in raw SQL wrap such expressions with `if(expr, 1, 0)`.
:::

## String comparisons and LIKE

MatrixOne ignores case-insensitive collations such as `utf8mb4_unicode_ci`: both `=` and `LIKE` compare case-sensitively. The driver restores MySQL's behaviour for LIKE:

| Query | Compiled to |
|-------|-------------|
| `where('name', 'like', 'a%')`, `whereLike('name', 'a%')` | `name ilike ?` |
| `where('name', 'not like', 'a%')`, `whereNotLike(...)` | `name not ilike ?` |
| `whereLike('name', 'A%', caseSensitive: true)`, `where('name', 'like binary', ...)` | `name like binary ?` |

::: warning Equality is case-sensitive
`where('email', 'Alice@example.com')` does **not** match `alice@example.com` on MatrixOne, while it does on MySQL with a `_ci` collation. The driver does not rewrite `=` because wrapping columns in `lower()` would prevent index use. Normalize such values when writing them (for example lower-case e-mails in a mutator) or compare with `whereLike()`.
:::

## JSON columns

Every JSON method of Laravel's MySQL grammar works, verified against MatrixOne 4.2.4:

| Method | Notes |
|--------|-------|
| `where('meta->a->b', ...)` with strings, numbers and `!=`, `>`... | Paths may use array indexes: `meta->list[1]->id` |
| `where('meta->flag', true)` | JSON booleans are compared as `'true'` / `'false'` text |
| `whereNull('meta->a')` / `whereNotNull('meta->a')` | A missing key, a JSON `null` and a SQL `NULL` document count as null |
| `whereIn()`, `whereBetween()`, `whereLike()` on JSON paths | |
| `whereJsonContains()` / `whereJsonDoesntContain()` | Scalars, arrays and objects |
| `whereJsonOverlaps()` / `whereJsonDoesntOverlap()` | Compiled as `json_overlaps(json_extract(col, path), value)` |
| `whereJsonContainsKey()` / `whereJsonDoesntContainKey()` | |
| `whereJsonLength()` | |
| `select('meta->a as a')`, `orderBy('meta->a')`, `groupBy('meta->a')` | |
| `update(['meta->a' => $value, 'meta->b' => $value])` | See below |
| Eloquent `array`, `json`, `AsArrayObject`, `AsCollection` casts and `$model->update(['meta->a' => 1])` | |

Updates of several paths of the same column are merged into one `json_set(meta, path1, value1, path2, value2, ...)`. MatrixOne evaluates every `SET` assignment against the original row, so Laravel's usual `meta = json_set(...), meta = json_set(...)` would keep only the last change. Booleans are written as JSON booleans, arrays as JSON documents, and floats as JSON numbers (MySQL through PDO stores them as strings).

`pluck('meta->a')` needs an alias (`pluck('meta->a as a')`), as on MySQL.

The `->` and `->>` operators also work in raw SQL; MatrixOne rewrites them to `json_extract()` and `json_unquote(json_extract())`.

## Full-text search

```php
DB::table('articles')->whereFullText('title', 'matrixone')->get();
DB::table('articles')->whereFullText(['title', 'body'], '+fast -slow', ['mode' => 'boolean'])->get();
DB::table('articles')->searchFullText(['title', 'body'], 'vector database')->get(); // ordered by relevance
```

See [Full-text Search](./full-text) for relevance scores, session variables such as `ft_relevancy_algorithm`, and limitations.

## Vector search

Laravel's own vector methods use cosine distance:

```php
Post::whereVectorSimilarTo('embedding', $vector, minSimilarity: 0.8)->get();
Post::orderByVectorDistance('embedding', $vector)->limit(10)->get();
Post::selectVectorDistance('embedding', $vector)->get(); // adds `embedding_distance`
```

The MatrixOne builder adds metric-aware helpers. Supported metrics are `cosine` (`cosine_distance`), `l2` (`l2_distance`) and `inner_product`.

```php
// The 10 nearest rows (cosine by default).
Post::nearestTo('embedding', [0.1, 0.2, 0.3], 10)->get();
Post::nearestTo('embedding', $vector, 5, metric: 'l2')->get();

Post::query()
    ->select('id', 'title')
    ->selectVectorDistanceUsing('l2', 'embedding', $vector, 'distance')
    ->whereVectorDistanceUsing('cosine', 'embedding', $vector, '<', 0.3)
    ->orderByVectorDistanceUsing('l2', 'embedding', $vector)
    ->get();
```

Vectors can be arrays, `Collection`s or MatrixOne literals such as `'[0.1,0.2]'`.
