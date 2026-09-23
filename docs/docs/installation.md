# Installation

- [Requirements](#requirements)
- [Install the package](#install-the-package)
- [Configure a connection](#configure-a-connection)
- [Configuration options](#configuration-options)
- [Running MatrixOne locally](#running-matrixone-locally)

## Requirements

| Component | Version |
|-----------|---------|
| PHP | 8.2+ with `pdo_mysql` |
| Laravel | 12.x or 13.x |
| MatrixOne | 4.2+ (tested on 4.2.4) |

## Install the package

```bash
composer require vuthaihoc/laravel-matrixone
```

The service provider is auto-discovered. It registers the `matrixone` database driver.

## Configure a connection

Add a connection to `config/database.php`:

```php
'connections' => [
    'matrixone' => [
        'driver' => 'matrixone',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', 6001),
        'database' => env('DB_DATABASE', 'laravel'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', '111'),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'prefix_indexes' => true,
        'strict' => true,
        'engine' => null,
        'options' => [],
    ],
],
```

To use MatrixOne as the application's main database, set it as the default:

```dotenv
DB_CONNECTION=matrixone
DB_HOST=127.0.0.1
DB_PORT=6001
DB_DATABASE=laravel
DB_USERNAME=root
DB_PASSWORD=111
```

The database itself must exist. Create it once with any MySQL client:

```sql
create database laravel;
```

## Configuration options

The driver accepts every option of Laravel's `mysql` driver (`read` / `write` hosts, `sticky`, `unix_socket`, `timezone`, `isolation_level`, `modes`, `options`...). MatrixOne-specific options:

| Option | Default | Description |
|--------|---------|-------------|
| `variables` | `[]` | MatrixOne session variables applied with `SET SESSION` on every connect and reconnect, e.g. `['ft_relevancy_algorithm' => 'BM25', 'experimental_hnsw_index' => 1]`. See [Full-text Search › Session variables](./full-text#session-variables). |
| `ignore_json_defaults` | `false` | MatrixOne rejects default values on JSON columns. By default a migration declaring one fails with a clear error. Set to `true` to drop such defaults instead; the column then becomes nullable so inserts that relied on the default keep working (they store `NULL`). |
| `ignore_json_indexes` | `false` | MatrixOne cannot index JSON columns. By default an `index()` / `unique()` on a JSON column fails with a clear error. Set to `true` to skip those indexes. Useful when running migrations written for PostgreSQL (`->algorithm('GIN')`). |
| `emulate_prepares` | `true` | Use PDO emulated prepares. MatrixOne rejects placeholders in some positions (for example inside `MATCH ... AGAINST`) and its server-side prepared statements have known metadata caching issues. Set to `false` to use native prepares. An explicit `PDO::ATTR_EMULATE_PREPARES` in `options` takes precedence. |

## Running MatrixOne locally

```bash
docker run -d -p 6001:6001 --name matrixone matrixorigin/matrixone:4.2.4
```

The default account is `root` with password `111`. See [Running MatrixOne with Docker](./docker) for a persistent standalone setup and for storing data on S3.

## Lost connections and restarts

Laravel's reconnect logic works unchanged: when MatrixOne closes a session ("MySQL server has gone away", e.g. after `KILL` or a server restart), the next query outside a transaction reconnects and is retried. Inside a transaction the query fails, as on MySQL, because the server already rolled the transaction back; the transaction level is reset and later queries reconnect. Session variables from the `variables` option are applied again on every reconnect. Long-running processes (queue workers, Octane) therefore survive a MatrixOne restart without special handling.

If MatrixOne panics while executing a statement, the connection is left unusable; the driver drops it so the next query reconnects (see [Compatibility › Known MatrixOne issues](./compatibility#known-matrixone-issues)).

## Command line

`php artisan db` opens the `mysql` client on a MatrixOne connection (MatrixOne speaks the MySQL protocol), so the `mysql` client must be installed:

```bash
php artisan db matrixone
```

`db:show`, `db:table`, `db:wipe`, `db:monitor` and the `migrate:*` commands work as with MySQL. `schema:dump` is not supported yet.

## Cache, queue and sessions

Laravel's `database` drivers work on MatrixOne with the tables created by the application skeleton (`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`):

```dotenv
DB_CONNECTION=matrixone
CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=database
```

Covered by the test suite: cache reads/writes, expiration, `add()`, `increment()`, locks, the rate limiter, queued, delayed and released jobs, failed jobs and `queue:retry`, job batches, and sessions.

MatrixOne has no `SKIP LOCKED`, so queue workers popping jobs at the same time wait for each other instead of skipping locked rows. Jobs are never run twice, but many concurrent workers on one queue scale worse than on MySQL 8.
