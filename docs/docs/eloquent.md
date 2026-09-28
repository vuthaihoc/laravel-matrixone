# Eloquent

- [Models](#models)
- [Vector attributes](#vector-attributes)
- [Transactions](#transactions)

## Models

Use Laravel's standard `Illuminate\Database\Eloquent\Model`. No base class is needed:

```php
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $connection = 'matrixone'; // omit when MatrixOne is the default connection
}
```

Auto-increment keys, timestamps, relationships (including eager loading with per-parent limits), soft deletes, `firstOrCreate`, `createOrFirst`, `updateOrCreate`, `upsert` and JSON casts all work as on MySQL.

## Vector attributes

Cast a `vecf32` / `vecf64` column with `AsVector` to read and write PHP arrays:

```php
use MatrixOne\Eloquent\Casts\AsVector;

class Post extends Model
{
    protected function casts(): array
    {
        return ['embedding' => AsVector::class];
    }
}

$post = Post::create(['title' => 'Hello', 'embedding' => [0.1, 0.2, 0.3]]);
$post->embedding; // [0.1, 0.2, 0.3]

Post::nearestTo('embedding', [0.1, 0.2, 0.25], 5)->get();
```

See [Query Builder › Vector search](./query-builder#vector-search) for every vector query method.

## Transactions

`DB::transaction()`, `beginTransaction()`, `commit()`, `rollBack()` and `afterCommit()` work normally.

### Conflicts and deadlocks

MatrixOne rolls back the whole transaction on a write-write conflict (`w-w conflict`), a deadlock (it may pick every transaction of the cycle as a victim), a lock wait timeout or a CN rolling restart, and reports them with SQLSTATE `HY000` and its own error numbers (20619, 20628, 20631, 20634, 20701-20704, 20709, 20710). The driver retries them:

- `DB::transaction($callback)` without an attempt count runs the callback up to `retry_attempts` times. An explicit count (`DB::transaction($callback, 5)`) is kept. Keep side effects that must not repeat out of the callback, or use `DB::afterCommit()` / `afterCommit` jobs.
- A statement outside a transaction that fails with one of these errors is retried.
- `beginTransaction()` / `commit()` by hand are not retried: wrap the work in `DB::transaction()`.
- "txn commit status is unknown" (20638) is never retried: the commit may have happened.

Each retry waits an exponential backoff with jitter:

```php
'matrixone' => [
    // ...
    'retry_attempts' => 3,     // 1 disables the retries
    'retry_base_delay' => 50,  // ms, doubled on every retry
    'retry_max_delay' => 1000, // ms
],
```

::: warning Nested transactions
MatrixOne has no `ROLLBACK TO SAVEPOINT`. Nested transactions are flattened into the outermost one: an inner `commit()` only lowers the level, and an inner `rollBack()` does **not** undo the inner writes — only rolling back the outermost transaction does. Avoid relying on partial rollbacks.
:::

When the outer transaction catches an inner failure, the inner writes are committed with the outer ones:

```php
DB::transaction(function () {
    User::create(['name' => 'Outer']);

    try {
        DB::transaction(function () {
            User::create(['name' => 'Inner']);
            throw new RuntimeException();
        });
    } catch (RuntimeException) {}
});
// MySQL keeps "Outer" only; MatrixOne keeps both.
```

To fail loudly instead, set `nested_transactions` to `rollback_only` on the connection. An inner rollback then marks the whole transaction, and the outermost commit rolls everything back and throws `MatrixOne\NestedTransactionRolledBackException`:

```php
'matrixone' => [
    'driver' => 'matrixone',
    // ...
    'nested_transactions' => 'rollback_only',   // default: 'flatten'
],
```
