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

::: warning Raw boolean expressions
MatrixOne returns boolean expressions such as `select a = b` as the strings `"true"` and `"false"`. In PHP `(bool) "false"` is `true`. The driver handles this for its own queries; in raw SQL wrap such expressions with `if(expr, 1, 0)`.
:::

## JSON columns

| Method | Supported |
|--------|-----------|
| `where('meta->a->b', ...)`, `select('meta->a')`, `orderBy('meta->a')` | ✓ |
| `where('meta->flag', true)` | ✓ |
| `update(['meta->a' => 1])` | ✓ |
| `whereJsonContainsKey()` / `whereJsonDoesntContainKey()` | ✓ (a key explicitly set to JSON `null` counts as missing) |
| `whereJsonContains()`, `whereJsonOverlaps()`, `whereJsonLength()` | ✗ throws `RuntimeException` |

## Full-text search

```php
DB::table('articles')->whereFullText('title', 'matrixone')->get();
DB::table('articles')->whereFullText(['title', 'body'], '+fast -slow', ['mode' => 'boolean'])->get();
```

A full-text index is required, see [Schema](./schema#full-text-indexes).

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
