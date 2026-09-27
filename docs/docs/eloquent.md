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
