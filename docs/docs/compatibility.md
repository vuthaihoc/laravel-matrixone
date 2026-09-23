# MatrixOne Compatibility

MatrixOne speaks the MySQL 8.0 protocol but implements a subset of MySQL. This page lists every difference the driver handles, verified against MatrixOne 4.2.4 (and 3.0.9 where noted).

## Handled transparently

| MatrixOne behaviour | What the driver does |
|---------------------|----------------------|
| Boolean expressions return `"true"`/`"false"` strings | `exists()`, `hasTable()`, index `unique` and schema `default` flags are computed as integers |
| No `insert ... as alias` row alias | `upsert()` uses `values(col)` |
| No `lock in share mode` / `for share` | Shared locks become `for update` |
| `RAND()` accepts no seed | The seed is dropped |
| No `performance_schema` | `threadCount()` counts `information_schema.processlist` |
| No savepoints | Nested transactions are flattened (see [Eloquent › Transactions](./eloquent#transactions)) |
| `json_overlaps()` takes exactly two documents | The JSON path is applied with `json_extract()` |
| `SET` assignments all read the original row | JSON path updates of one column share one `json_set()` |
| `LIKE` ignores `_ci` collations | `like` / `whereLike()` compile to `ilike` |
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

- `=` compares strings case-sensitively even on `_ci` collations; the driver cannot change this without losing index use.
- **4.2.4:** inserting into a table with both a foreign key and a FULLTEXT index panics in the query planner. Keep full-text indexes on tables without their own foreign keys.
- **FULLTEXT2** indexes (4.2.2+) are experimental and are not indexed synchronously; the driver does not use them.
- **HNSW** vector indexes are experimental; the driver enables them for the creating session only.
