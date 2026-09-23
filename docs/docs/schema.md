# Schema

- [Migrations](#migrations)
- [Column types](#column-types)
- [Indexes](#indexes)
- [Full-text indexes](#full-text-indexes)
- [Vector columns and indexes](#vector-columns-and-indexes)
- [Altering tables](#altering-tables)
- [Introspection](#introspection)

## Migrations

`php artisan migrate`, `migrate:rollback`, `migrate:fresh`, `migrate:status`, `db:wipe`, `db:show` and `db:table` work with the standard migration repository. The default Laravel migrations (`users`, `cache`, `jobs`, `sessions`...) run unchanged.

`schema:dump` is not supported.

## Column types

| Blueprint method | MatrixOne type | Notes |
|------------------|----------------|-------|
| `id()`, `increments()`, integer types | `bigint`, `int`... | `unsigned` supported |
| `string()`, `char()`, `text()` variants | `varchar`, `char`, `text` | |
| `decimal()`, `float()`, `double()` | same | |
| `boolean()` | `tinyint(1)` | |
| `enum()` | `enum` | |
| `json()`, `jsonb()` | `json` | no default values, no indexes (see below) |
| `date()`, `dateTime()`, `time()`, `timestamp()` | same | precision, `useCurrent()`, `useCurrentOnUpdate()` supported |
| `year()` | `smallint` | MatrixOne has no `YEAR` type |
| `uuid()`, `ulid()` | `char(36)`, `char(26)` | MatrixOne's native `UUID` type is not readable by PHP's mysqlnd |
| `binary()`, `ipAddress()`, `macAddress()` | same as MySQL | |
| `vector($column, $dims)` | `vecf32(dims)` | dimensions are required |
| `vector64($column, $dims)` | `vecf64(dims)` | Blueprint macro added by the driver |
| `set()` | ✗ | throws |
| `geometry()`, `geography()` | ✗ | throws |
| `virtualAs()`, `storedAs()` | ✗ | generated columns throw |

`id()->from(1000)` sets the auto-increment start value when creating a table; MatrixOne cannot change it on an existing table.

## Indexes

`primary()`, `unique()`, `index()` and their `drop*` counterparts work. The index algorithm argument (`USING BTREE`, `GIN`...) is ignored because MatrixOne rejects it. `renameIndex()` is emulated by dropping and re-creating the index.

### Differences from MySQL

| Case | Behaviour |
|------|-----------|
| Unique index on a `_ci` column | **Case-sensitive**: `bob@example.com` and `BOB@example.com` are both accepted, while MySQL rejects the second. Normalize such values with the `MatrixOne\Eloquent\Casts\Lowercase` cast, see [Query Builder › Case-insensitive equality](./query-builder#case-insensitive-equality). |
| Unique index on a nullable column | Several `NULL` values are allowed, as in MySQL. |
| Index on a `TEXT` column | Rejected (MySQL requires a prefix length). Use a prefix index, `$table->rawIndex('body(100)', 'posts_body_prefix')`, or a FULLTEXT index. |
| Functional / expression index (`rawIndex('(lower(name))')`, `((meta->>'$.kind'))`) | Rejected; MatrixOne only has a draft proposal for them. Add a regular column holding the computed value and index it. |
| Spatial index | Not available (no spatial types). |

Index names longer than MatrixOne's 64-character limit are shortened to a 56-character prefix plus a hash of the full name. The same shortening is applied by `dropIndex()`, `dropUnique()`, `renameIndex()` and `Schema::hasIndex()`, so Laravel's generated names keep working. `Schema::getIndexes()` reports the shortened name.

### JSON columns

MatrixOne rejects default values on JSON columns and cannot index them. By default the driver fails with a clear error instead of sending invalid DDL. For migrations written for another database, two connection options relax this (see [Installation › Configuration options](./installation#configuration-options)):

- `ignore_json_defaults` drops the default and makes the column nullable.
- `ignore_json_indexes` skips `index()` / `unique()` on JSON columns.

JSON columns are detected among the columns of the same blueprint and, when altering an existing table, from the catalog.

Foreign keys (`foreignId()->constrained()`, `cascadeOnDelete()`, `dropForeign()`) are supported, and `Schema::disableForeignKeyConstraints()` works.

## Full-text indexes

```php
$table->fullText('body');
$table->fullText(['title', 'body'])->parser('ngram');
$table->fullText('meta')->parser('json');   // JSON columns
```

See [Full-text Search](./full-text) for parsers, relevance, session variables, FULLTEXT2 and the MatrixOne 4.2.4 foreign-key crash.

## Vector columns and indexes

Migrations keep type-hinting Laravel's `Illuminate\Database\Schema\Blueprint`; the driver adds its methods as Blueprint macros.

```php
use Illuminate\Database\Schema\Blueprint;

Schema::create('documents', function (Blueprint $table) {
    $table->id();
    $table->text('content');
    $table->vector('embedding', 1536);      // vecf32
    $table->vector64('precise', 3);         // vecf64 (macro)

    $table->vectorIndex('embedding')->lists(100);   // IVF-Flat, cosine distance
});
```

`vectorIndex()` always builds an **IVF-Flat** index. Laravel's own `vectorIndex()` sets the algorithm to `hnsw` (pgvector's default); the driver ignores that because MatrixOne's HNSW index is experimental, needs a signed `BIGINT` primary key (Laravel's `id()` is unsigned) and is maintained asynchronously.

| Fluent option | Effect |
|---------------|--------|
| `->lists(100)` | IVF-Flat list count |
| `->operatorClass('cosine' \| 'l2' \| 'ip')` | Distance: `vector_cosine_ops` (default), `vector_l2_ops`, `vector_ip_ops` |
| `->hnsw()` | Build HNSW instead (the driver enables `experimental_hnsw_index` for the statement's session) |
| `->m(16)`, `->efConstruction(64)`, `->efSearch(40)` | HNSW tuning |

HNSW example:

```php
Schema::create('documents', function (Blueprint $table) {
    $table->bigInteger('id', autoIncrement: true);   // signed BIGINT key, required by HNSW
    $table->vector('embedding', 768);
    $table->vectorIndex('embedding')->hnsw()->m(16);
});

// New rows reach an HNSW index asynchronously; force it after bulk writes:
DB::statement('alter table documents alter reindex documents_embedding_vectorindex hnsw force_sync');
```

Drop an index with `$table->dropVectorIndex(['embedding'])`. Vector columns cannot be part of a primary or unique key.

## Altering tables

Adding, changing (`->change()`), renaming and dropping columns, table comments and `Schema::rename()` work. Each Blueprint command runs as its own statement, which MatrixOne requires when adding a column and an index together.

## Introspection

`Schema::getTables()`, `getViews()`, `getColumns()`, `getIndexes()`, `getForeignKeys()`, `hasTable()`, `hasColumn()` and `hasIndex()` return Laravel's documented shapes:

- MatrixOne's system databases (`mo_catalog`, `mo_task`, `system`...) are excluded.
- Hidden columns (`__mo_fake_pk_col` for tables without a primary key) are excluded.
- Foreign keys are read from `mo_catalog.mo_foreign_keys`, because `information_schema.key_column_usage` is empty in MatrixOne.
- Table `size` is always `0`.
