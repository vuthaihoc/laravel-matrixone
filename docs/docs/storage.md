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
| `flushTable($table)` | `mo_ctl('dn', 'flush', 'db.table')` | one table | about 0.5 s |
| `checkpoint()` | `mo_ctl('dn', 'checkpoint', '')` | every table | about 1.5 to 4 s |
| `checkpoint(global: true)` | `mo_ctl('dn', 'globalcheckpoint', '')` | every table | about 2 s |

`mo_ctl` needs an administrative user (root, or the admin of the `sys` account). Other users get `do not have privilege to execute the statement`, even with every privilege on the database. Use a separate connection for these calls:

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

```bash
php artisan matrixone:flush orders payments --database=matrixone_admin
php artisan matrixone:flush --all --database=matrixone_admin           # every table of the database
php artisan matrixone:flush --checkpoint --database=matrixone_admin    # every table of every database
php artisan matrixone:flush --checkpoint --global --database=matrixone_admin
```

Flush important tables more often than MatrixOne's own background jobs do:

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
