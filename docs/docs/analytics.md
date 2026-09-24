# Analytics

MatrixOne is an HTAP database: it stores tables in columnar format and runs vectorized, parallel scans. The tables your application writes to can therefore be aggregated directly, with no copy to a separate warehouse. This page covers what works through Laravel and the MatrixOne-specific helpers the driver adds.

- [SQL analytics](#sql-analytics)
- [Time windows](#time-windows)
- [Sampling](#sampling)
- [Snapshots and time travel](#snapshots-and-time-travel)
- [Clustering tables](#clustering-tables)
- [Loading data](#loading-data)

## SQL analytics

Window functions, multi-level grouping and statistical aggregates work through `selectRaw()` / `groupByRaw()` as on MySQL:

```php
// Rank and running totals
DB::table('sales')
    ->select('region', 'amount')
    ->selectRaw('rank() over (partition by region order by amount desc) as position')
    ->selectRaw('sum(amount) over (order by sold_at rows between 6 preceding and current row) as weekly')
    ->get();

// Subtotals per region and a grand total
DB::table('sales')
    ->selectRaw('region, product, sum(amount) as total, grouping(region) as is_total')
    ->groupByRaw('region, product with rollup')
    ->get();

DB::table('sales')->selectRaw('region, product, sum(amount)')->groupByRaw('grouping sets ((region), (product))')->get();
DB::table('sales')->selectRaw('region, product, sum(amount)')->groupByRaw('cube(region, product)')->get();

// Statistics
DB::table('sales')->selectRaw('median(amount), approx_percentile(amount, 0.95), approx_count_distinct(customer_id)')->first();
```

Verified on MatrixOne 4.2.4:

| Works | Not supported |
|-------|---------------|
| `rank`, `dense_rank`, `row_number`, `lag`, `lead`, `ntile`, `percent_rank`, frames (`rows between`) | Named windows (`window w as (...)`), `QUALIFY` |
| `WITH ROLLUP`, `GROUPING SETS`, `CUBE`, `grouping()` | |
| `median`, `approx_percentile`, `approx_count_distinct`, `stddev_pop`, `var_pop`, `group_concat`, `any_value` | `percentile_cont(...) within group` |
| Bitmap aggregates (`bitmap_construct_agg`, `bitmap_count`, …) for exact distinct counts | `EXCEPT ALL`, `JSON_TABLE`, materialized views |
| Recursive CTEs, `generate_series()`, `unnest()` of JSON arrays | |

## Time windows

MatrixOne aggregates rows into time windows with its `INTERVAL ... SLIDING ... FILL` clause. Use `timeWindow()` and select the window bounds `_wstart` / `_wend` next to aggregates:

```php
// Sum per 10-second window
DB::table('metrics')
    ->select('_wstart', '_wend', DB::raw('sum(value) as total'))
    ->where('device', 'sensor-1')
    ->timeWindow('recorded_at', '10 seconds')
    ->orderBy('_wstart')
    ->get();

// 1-minute windows every 30 seconds; empty windows repeat the previous value
DB::table('metrics')
    ->select('_wstart', DB::raw('avg(value) as average'))
    ->timeWindow('recorded_at', '1 minute', sliding: '30 seconds', fill: 'prev')
    ->get();

// Missing values set to 0
->timeWindow('recorded_at', '1 hour', fill: 'value', fillValue: 0)
```

- Durations are `"<n> second|minute|hour|day"`. MatrixOne supports no other unit (no week or month).
- `fill` is `prev`, `next`, `linear`, `null`, `none` or `value` (with `fillValue`).
- Every selected column other than `_wstart` / `_wend` must be an aggregate. MatrixOne rejects `GROUP BY` and `HAVING` with a time window, and the builder throws before sending them. Filter in `where()`, or wrap the query in `fromSub()` to filter the windows.
- `count()`, `sum()`, `paginate()` and the other aggregates run over the windows (as a subquery): `count()` returns the number of windows.

## Sampling

`sample()` returns random rows through MatrixOne's `SAMPLE()` function. It is much cheaper than `inRandomOrder()->limit()` on large tables:

```php
DB::table('events')->sample(100)->get();                       // at most 100 random rows
DB::table('events')->where('type', 'click')->sample(100)->get(); // filters apply before sampling
Event::query()->sample(100)->get();                           // Eloquent models
DB::table('events')->samplePercent(0.5)->get();               // each row with a 0.5% probability

// 3 random ids per country
DB::table('users')->select('country')->sample(3, 'id')->groupBy('country')->get();
```

- `samplePercent()` takes 0.01 to 99.99; the number of rows varies between runs.
- MatrixOne refuses aliases on a sample of several columns (`select('id as user_id')->sample(10)`).
- `count()` and other aggregates run over the sample.

## Snapshots and time travel

A snapshot records the state of a database or table. A PITR (point-in-time recovery) range keeps enough history to read any past moment within it. Both are read with ordinary queries:

```php
use Illuminate\Support\Facades\DB;

$db = DB::connection('matrixone');

$db->createSnapshot('before_import');                  // the connection's database
$db->createSnapshot('orders_eod', 'orders');           // one table
$db->createAccountSnapshot('nightly');                 // every database of the account
$db->getSnapshots();                                   // [['snapshot_name' => ..., 'timestamp' => ..., 'snapshot_level' => ...], ...]
$db->hasSnapshot('before_import');
$db->dropSnapshot('before_import');

$db->createPitr('orders_pitr', 7, 'd', 'orders');      // 7 days of history (units: h, d, mo, y)
$db->alterPitr('orders_pitr', 30, 'd');
$db->getPitrs();
$db->dropPitr('orders_pitr');
```

```php
// The table as it was in the snapshot
DB::table('orders')->asOfSnapshot('orders_eod')->sum('total');
Order::query()->asOfSnapshot('orders_eod')->where('status', 'paid')->count();

// The table as it was at a point in time (within a PITR range)
DB::table('orders')->asOfTimestamp(now()->subHour())->count();

// Compare the past with the present: rows deleted since the snapshot
DB::table('orders')->asOfSnapshot('orders_eod')
    ->whereNotIn('id', DB::table('orders')->select('id'))
    ->get();
```

- Time travel applies to the query's `from` table. For a join, time-travel the subquery: `->joinSub(DB::table('orders')->asOfSnapshot('eod'), 'old', ...)`.
- `asOfTimestamp()` interprets the time in the connection's session time zone.
- Snapshot and PITR names accept letters, digits, `_` and `-`. Snapshots are account-wide, so choose unique names.

From the command line:

```bash
php artisan matrixone:snapshot create before_deploy
php artisan matrixone:snapshot create orders_eod --table=orders
php artisan matrixone:snapshot list
php artisan matrixone:snapshot drop before_deploy
php artisan matrixone:pitr create orders_pitr --range=7d --table=orders
php artisan matrixone:pitr alter orders_pitr --range=30d
php artisan matrixone:pitr list
```

### Restoring data

`RESTORE ... FROM SNAPSHOT` is a syntax error on MatrixOne 4.2.4 (see [Compatibility › Known MatrixOne issues](./compatibility#known-matrixone-issues)). Copy the rows back from the snapshot instead:

```php
DB::table('orders')->insertUsing(
    ['id', 'customer_id', 'total', 'created_at'],
    DB::table('orders')->asOfSnapshot('orders_eod')
        ->select('id', 'customer_id', 'total', 'created_at')
        ->whereNotIn('id', DB::table('orders')->select('id'))
);
```

## Clustering tables

`CLUSTER BY` sorts a table's data by the given columns, so range filters on them (per device, per day) read fewer blocks:

```php
Schema::create('metrics', function (Blueprint $table) {
    $table->dateTime('recorded_at', 3);
    $table->string('device');
    $table->double('value');
    $table->unique(['device', 'recorded_at']);
    $table->clusterBy(['device', 'recorded_at']);
});
```

- MatrixOne does not accept `CLUSTER BY` on a table with a primary key (so no `id()`), and the builder throws if you try. A unique index is allowed. Use clustered tables for append-only facts, such as events and metrics.
- It can only be declared when the table is created.

## Loading data

For bulk loads, use MatrixOne's statements directly:

```php
DB::statement("load data infile '/data/events.csv' into table events fields terminated by ',' ignore 1 lines");
DB::statement("create stage archive url = 's3://bucket/events/' credentials = {...}");
DB::statement("create external table events_2025 (...) infile 'stage://archive/2025/*.parquet'");
```

See MatrixOne's `LOAD DATA`, `CREATE STAGE` and `CREATE EXTERNAL TABLE` references. The driver does not wrap them yet.
