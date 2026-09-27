# Storage and Flushing

MatrixOne commits writes to its write-ahead log (the log service) and keeps recent data in memory. Background jobs later write it to object storage (S3 or the local disk) as immutable objects. A committed write is durable as soon as the WAL has it. Flushing only moves data from the WAL and memory into object storage earlier.

Flush earlier when object storage is what you back up or replicate, or when the log service disks are less safe than the bucket. In an S3-backed deployment such as `docker/s3`, for example, the WAL is on local disks and only the objects are in the bucket.

::: tip
Bulk loads (`insert ... select`, `LOAD DATA`, large batches) are written to object storage directly. Small, frequent inserts and updates are the writes that wait in the WAL and memory.
:::

## Flush from code

```php
use Illuminate\Support\Facades\DB;

$admin = DB::connection('matrixone_admin');

$admin->flushTable('orders');                    // one table of the connection's database
$admin->flushTables(['orders', 'payments']);     // several tables
$admin->flushTables();                           // every table of the database
$admin->checkpoint();                            // every table of every database, then truncate the WAL
$admin->checkpoint(global: true);                // a global checkpoint
```

| Method | MatrixOne command | Scope | Time (local 4.2.4) |
|--------|-------------------|-------|--------------------|
| `flushTable($table)` | `mo_ctl('dn', 'flush', 'db.table')` | one table of the `sys` account | about 0.5 s |
| `checkpoint()` | `mo_ctl('dn', 'checkpoint', '')` | every table of every account | about 1.5 to 4 s |
| `checkpoint(global: true)` | `mo_ctl('dn', 'globalcheckpoint', '')` | every table | about 2 s |

## Permissions and accounts (tenants)

`mo_ctl` needs the admin of the `sys` account (`root`). Measured on MatrixOne 4.2.4:

| Who runs it | Flush a table | Checkpoint |
|-------------|---------------|------------|
| A user with every privilege on the database | refused (`do not have privilege`) | refused |
| The admin of another account (tenant), e.g. `acme:admin` | refused, even for its own tables | refused |
| `root` (sys account) | only tables of the `sys` account | every table of **every account** |

So when each project has its own account, a project cannot flush its own tables, and `root` cannot flush a single tenant table either. The flush command then fails with `did nothing: MatrixOne only flushes tables of the sys account`. What works is a **checkpoint run centrally with root**. It covers every tenant in a few seconds.

- Do not give `root` to each application. Keep it on one operations host or one admin application that runs the checkpoint schedule (a system cron job calling the MatrixOne client works too).
- Applications keep least-privilege users on their own databases.
- Per-table flushes (`flushTable()`, `matrixone:flush orders`) are for tables in the `sys` account, with `root`.

A separate connection for the admin calls:

```php
// config/database.php
'matrixone_admin' => [
    'driver' => 'matrixone',
    'host' => env('MO_HOST', '127.0.0.1'),
    'port' => env('MO_PORT', 6001),
    'database' => env('MO_DATABASE', 'laravel'),
    'username' => env('MO_ADMIN_USERNAME', 'root'),
    'password' => env('MO_ADMIN_PASSWORD'),
],
```

## Flush on a schedule

On one operations host (with `root`), whatever the number of tenants:

```php
// routes/console.php of the admin application
Schedule::command('matrixone:flush --checkpoint --database=matrixone_admin')
    ->everyTenMinutes()
    ->withoutOverlapping();
```

Or, without Laravel:

```bash
*/10 * * * * mysql -h 127.0.0.1 -P 6001 -u root -p"$MO_ROOT_PASSWORD" -e "select mo_ctl('dn', 'checkpoint', '')"
```

For tables in the `sys` account, per-table flushes:

```bash
php artisan matrixone:flush orders payments --database=matrixone_admin
php artisan matrixone:flush --all --database=matrixone_admin           # every table of the database
php artisan matrixone:flush --checkpoint --database=matrixone_admin    # every table of every database
php artisan matrixone:flush --checkpoint --global --database=matrixone_admin
```

Flush important tables more often than MatrixOne's own background jobs do (sys account only):

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('matrixone:flush orders payments --database=matrixone_admin')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('matrixone:flush --checkpoint --database=matrixone_admin')
    ->hourly()
    ->withoutOverlapping();
```

A flush of a table with nothing new in memory is cheap. A checkpoint writes every table and takes seconds, so schedule it less often than per-table flushes.

## Check what is in object storage

`metadata_scan()` lists a table's objects. Rows that only live in the WAL and memory are not counted:

```php
DB::scalar("select coalesce(sum(rows_cnt), 0) from metadata_scan('laravel.orders', 'id') g");
```
