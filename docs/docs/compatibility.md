# MatrixOne Compatibility

MatrixOne speaks the MySQL 8.0 protocol but implements a subset of MySQL. This page lists every difference the driver handles, verified against MatrixOne 4.2.4 (and 3.0.9 where noted).

## Handled transparently

| MatrixOne behaviour | What the driver does |
|---------------------|----------------------|
| Boolean expressions return `"true"`/`"false"` strings | `exists()`, `hasTable()`, index `unique` and schema `default` flags are computed as integers |
| No `insert ... as alias` row alias | `upsert()` uses `values(col)` |
| Assigning a primary key `on duplicate key` is rejected | `upsert()` never updates its `uniqueBy` columns (Laravel's database cache upserts every column) |
| No `skip locked` / `nowait` | Dropped from lock clauses; the database queue's `FOR UPDATE SKIP LOCKED` becomes `for update` |
| No `lock in share mode` / `for share` | Shared locks become `for update` |
| `RAND()` accepts no seed | The seed is dropped |
| No `performance_schema` | `threadCount()` counts `information_schema.processlist` |
| No savepoints | Nested transactions are flattened (see [Eloquent › Transactions](./eloquent#transactions)) |
| `json_overlaps()` takes exactly two documents | The JSON path is applied with `json_extract()` |
| `SET` assignments all read the original row | JSON path updates of one column share one `json_set()` |
| `LIKE` ignores `_ci` collations | `like` / `whereLike()` compile to `cast(col as text) ilike` |
| `ILIKE` rejects numbers and dates | Columns are cast to text first |
| `MATCH ... AGAINST` cannot be OR-ed with other conditions | The Scout engine uses a subquery; see [Full-text › Limitations](./full-text#limitations) |
| `LAST_INSERT_ID()` is wrong on tables with a FULLTEXT index | `insertGetId()` uses `insert ... returning` |
| Identifiers are limited to 64 characters | Long index names are shortened consistently |
| JSON booleans cannot be compared with SQL `true` | Compared as unquoted text |
| `TRUNCATE` refused on tables referenced by foreign keys | Falls back to `DELETE` |
| Unconditional `DELETE` with foreign key checks disabled corrupts FK metadata | `where 1 = 1` is appended |
| `ALTER TABLE ... AUTO_INCREMENT = n` unsupported | Starting values are set in `CREATE TABLE` |
| No `RENAME INDEX` | Drop and re-create |
| `USING BTREE` rejected | Index algorithm omitted |
| Several tables cannot be dropped in one statement | `db:wipe` drops one table at a time |
| `information_schema.key_column_usage` is empty for FKs | Foreign keys read from `mo_catalog.mo_foreign_keys` |
| Hidden `__mo_*` columns and system databases | Excluded from introspection |
| Placeholders rejected in `MATCH ... AGAINST` with native prepares | Emulated prepares are the default |
| Native `UUID` column type unreadable by mysqlnd | `uuid()` stays `char(36)` |
| No `YEAR` type | `year()` becomes `smallint` |

## Minor differences

- Fractional-second columns: MatrixOne may omit a zero fraction when returning values (`2026-01-01 00:00:00` instead of `2026-01-01 00:00:00.000000`), e.g. for `datetime(6)`. Eloquent date casts parse both forms; compare raw strings with care.
- As with Laravel's MySQL driver, `DateTime`/Carbon bindings are formatted as `Y-m-d H:i:s` (no microseconds). Pass a formatted string to compare fractional seconds.

## Not supported (throws)

- Full-text query expansion (`whereFullText(..., ['expanded' => true])`)
- `set()`, `geometry()`, `geography()` columns
- Generated columns (`virtualAs()`, `storedAs()`)
- Default values on JSON columns (unless `ignore_json_defaults` is set)
- Indexes on JSON columns (unless `ignore_json_indexes` is set)
- `joinLateral()`
- `schema:dump`
- Changing the auto-increment start of an existing table

## Known MatrixOne issues

The package's `tests/KnownIssues` suite reproduces each open issue in plain SQL and asserts MySQL's behaviour, so it fails while the issue exists. Run it against a new MatrixOne release to see what got fixed:

```bash
composer test:known-issues
```

- **4.2.4 panic on correlated counts.** `loadCount()` / `withCount()` on a single parent selected by primary key (`find($id)`, `whereIn('id', [$id])`) crashes the server when the related query has an extra condition, e.g. SoftDeletes (`Error reading result set's header`). Count on the relation instead: `$user->posts()->count()`. The driver drops the broken connection, forgetting its transaction, so later queries reconnect instead of blocking on the row locks the dead session still holds.
- `=` compares strings case-sensitively even on `_ci` collations; the driver cannot change this without losing index use. Use the `Lowercase` cast or `whereIgnoreCase()` ([Query Builder › Case-insensitive equality](./query-builder#case-insensitive-equality)).
- **4.2.4:** inserting into a table with both a foreign key and a FULLTEXT index panics in the query planner. Keep full-text indexes on tables without their own foreign keys.
- **FULLTEXT2** indexes (4.2.2+) are experimental and are not indexed synchronously; the driver does not use them.
- **HNSW** vector indexes are experimental: they need a signed `BIGINT` primary key and are maintained asynchronously (sync with `alter reindex ... hnsw force_sync`). `vectorIndex()` builds IVF-Flat unless `->hnsw()` is called.
