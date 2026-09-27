# Companion packages

Packages from the same author, tested together with this driver:

| Package | Use it with MatrixOne to |
|---------|--------------------------|
| [vuthaihoc/laravel-db-portable](https://github.com/vuthaihoc/laravel-db-portable) | Write queries and migrations that run on MatrixOne, MySQL, PostgreSQL/CockroachDB and SQLite (`whereJsonNumber()`, `sumJson()`, `orderByNullsLast()`, `incrementJson()`, `jsonKeyIndex()`, `trigramIndex()`, `forDriver()`…), and move an application to MatrixOne with `db-portable:scan`, `db-portable:audit` and `db-portable:copy`. |
| [vuthaihoc/cockroachdb-laravel](https://github.com/vuthaihoc/crdb2025) | Run the other side of a move from CockroachDB. Its `strict_integers` option keeps integer columns within MySQL ranges, so the data fits MatrixOne's schema. |

```bash
composer require vuthaihoc/laravel-matrixone
composer require vuthaihoc/laravel-db-portable:^0.3          # optional
```

A typical move from CockroachDB to MatrixOne:

```bash
php artisan db-portable:scan --target=matrixone             # SQL that MatrixOne will reject
php artisan migrate --database=matrixone
php artisan db-portable:audit --from=crdb --to=matrixone     # values that do not fit the new schema
php artisan db-portable:copy --from=crdb --to=matrixone
```
