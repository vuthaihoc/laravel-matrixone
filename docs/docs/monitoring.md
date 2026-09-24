# Monitoring

MatrixOne records every statement it runs in `system.statement_info`: the SQL text, duration, rows and bytes read, errors and, for slow statements, the execution plan. There is nothing to enable. MySQL's `slow_query_log`, `long_query_time` and `general_log` variables exist for compatibility, but MatrixOne's history lives in this table.

- [Statement history](#statement-history)
- [Slow queries from the command line](#slow-queries-from-the-command-line)
- [Execution plans](#execution-plans)
- [Table statistics](#table-statistics)
- [Server logs and metrics](#server-logs-and-metrics)
- [Configuration](#configuration)

## Statement history

`statementLog()` returns a query builder on `system.statement_info`. By default it keeps only your application's statements (not MatrixOne's internal SQL), on the connection's database, from the last hour:

```php
use Illuminate\Support\Facades\DB;

$log = DB::connection('matrixone')->statementLog();

// The 20 slowest statements of the last hour, of any type
$log->slowerThan(500)->slowest()->summary()->limit(20)->get();

// Failed statements of the last day, newest first
DB::connection('matrixone')->statementLog()->since('1d')->failed()->summary()->latest('request_at')->get();

// Slow UPDATEs on every database since a given time
DB::connection('matrixone')->statementLog()
    ->allDatabases()
    ->since(now()->subMinutes(30))
    ->ofType('Update', 'Delete')
    ->slowerThan(1000)
    ->get();

// Everything, including MatrixOne's internal SQL
DB::connection('matrixone')->statementLog()->includeInternal()->count();
```

| Method | Effect |
|--------|--------|
| `since('15m' \| '1h' \| '2d' \| DateTimeInterface)` | Time range (replaces the default hour) |
| `slowerThan($ms)` | `duration >= $ms` |
| `failed()` | `status = 'Failed'` |
| `ofType('Select', 'Insert', …)` | By `statement_type` |
| `allDatabases()` / `forDatabase($name)` | Replace the database filter |
| `includeInternal()` / `onlyApplication()` | MatrixOne's internal SQL or not |
| `slowest()` | `order by duration desc` |
| `summary()` | Useful columns plus `duration_ms` |

`summary()` selects `request_at`, `statement_id`, `statement_type`, `status`, `database`, `user`, `rows_read`, `bytes_scan`, `result_count`, `aggr_count`, `err_code`, `error`, `statement` and `duration_ms`. The table has more: `transaction_id`, `session_id`, `connection_id`, `response_at`, `duration` (nanoseconds), `exec_plan` and `stats`.

Good to know:

- **Times are UTC.** `request_at` is stored in UTC whatever the session time zone. `since()` converts a `DateTimeInterface` to UTC.
- **Statements appear a few seconds after they finish** (about 2 s locally).
- **Short, repeated statements are merged** into one row whose text starts with `/* N queries */`: `aggr_count` is N, and `duration` is their total.
- **Always filter by time** (`since()` does). The table grows quickly (hundreds of thousands of rows a day on a busy server), and an unfiltered scan takes seconds.
- MatrixOne's documentation creates views such as `slow_query` for this purpose. They only cover `SELECT` statements above 1 second and scan the whole table. `statementLog()` covers every statement type, takes any threshold and filters by time.

## Slow queries from the command line

```bash
php artisan matrixone:slow-queries                       # statements >= 1 s in the last hour
php artisan matrixone:slow-queries --since=1d --min=200  # >= 200 ms in the last day
php artisan matrixone:slow-queries --type=Select --type=Update --limit=50
php artisan matrixone:slow-queries --failed --since=6h   # failed statements with their errors
php artisan matrixone:slow-queries --all-databases --internal
php artisan matrixone:slow-queries --plan=01a0d1e1-e077-7c90-b6dc-66db263ca0ea
php artisan matrixone:slow-queries --database=matrixone_search   # another connection
```

The output lists the statement id, which `--plan` takes.

## Execution plans

MatrixOne keeps the executed plan, with per-operator timings, only for statements running for at least one second (`longQueryTime`). Faster statements have none:

```php
$plan = DB::connection('matrixone')->getStatementPlan($statementId);   // ExecutionPlan or null

foreach ($plan?->nodes() ?? [] as $node) {
    // step, id, name (Project, Filter, Join, Table Scan…), title, time_ms, wait_ms,
    // input_rows, output_rows, scan_bytes, memory_bytes
}

$plan->plan;   // the full decoded JSON
```

For a statement you can run again, `EXPLAIN ANALYZE` returns the same information immediately:

```php
DB::select('explain analyze '.$query->toRawSql());
```

## Table statistics

```php
DB::connection('matrixone')->tableStats('orders');
// [
//     'rows' => 120034,           // mo_table_rows()
//     'size' => 4194304,          // mo_table_size(), bytes
//     'columns' => 8,             // SHOW COLUMN_NUMBER
//     'values' => [               // SHOW TABLE_VALUES
//         'total' => ['min' => 0.5, 'max' => 1999.0],
//         'status' => ['min' => 'cancelled', 'max' => 'shipped'],
//         ...
//     ],
// ]

DB::connection('matrixone')->tableStats('orders', values: false);   // skip the min/max scan
```

`rows` and `size` come from statistics that MatrixOne refreshes asynchronously: right after a large insert they may still show the old values (0 for a new table) for about a minute. Use `count()` when you need an exact number.

Other statistics statements work through `DB::select()`: `SHOW TABLE_NUMBER FROM <database>`.

## Server logs and metrics

| Where | Content |
|-------|---------|
| `system.log_info` | Server log records (`level` info, warn, error, panic, fatal) |
| `system.error_info`, `system.rawlog` | Errors and raw log/trace records |
| `system.sql_statement_hotspot` | The most expensive recent statements (time, memory) |
| `system_metrics.*` | Stored metrics: statement counts and durations, errors, connections, CPU, memory, disk, storage usage |
| Status port (`status-port`, 7001 in the Docker setups) | Prometheus metrics when `enable-metric-to-prom = true` |
| `docker logs <container>` | JSON logs of each service |

```php
// Recent server errors
DB::table(DB::raw('system.log_info'))
    ->where('timestamp', '>', now('UTC')->subHour())
    ->whereIn('level', ['error', 'panic', 'fatal'])
    ->latest('timestamp')
    ->limit(50)
    ->get(['timestamp', 'level', 'message']);
```

A statement that crashed the server (`panic runtime error`) is recorded in `statement_info.error` with its stack trace, which is useful when reporting a MatrixOne bug.

## Configuration

Statement recording is configured in the `[observability]` section of each CN's TOML file (for example `etc/cn.toml` in the S3 Docker setup). Restart the server after changing it. The following keys exist in MatrixOne 4.2.4. MatrixOne does not document their defaults, so the values below are examples:

```toml
[observability]
longQueryTime = 1.0              # seconds; plans are kept for statements at least this long
disableStmtAggregation = false   # true: one row per statement, no "/* N queries */" merging
selectAggrThreshold = "200ms"    # statements faster than this may be merged
aggregationWindow = "5s"
skipRunningStmt = true
disableTrace = false             # true: stop recording statements (statement_info stays empty)
disableMetric = false
enable-metric-to-prom = true
status-port = 7001
```

The server log level is `[log] level = "info"` (`debug`, `info`, `warn`, `error`).

On the application side, Laravel's own tools measure queries from the client: `DB::whenQueryingForLongerThan()`, `DB::listen()`, Telescope's query watcher and Pulse's slow query recorder all work unchanged with the `matrixone` driver.
