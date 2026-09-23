# Integrations

- [Laravel Scout](#laravel-scout)
- [Laravel Pulse](#laravel-pulse)
- [Laravel Telescope](#laravel-telescope)

## Laravel Scout

Use the package's engine: `SCOUT_DRIVER=matrixone`. It supports `LIKE`, prefix and full-text columns, relevance ordering, and semantic/hybrid search on vector columns. Scout's own `database` engine only works for models without full-text columns. See [Full-text Search › Laravel Scout](./full-text#laravel-scout).

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
