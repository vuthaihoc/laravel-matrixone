# Laravel MatrixOne

A [MatrixOne](https://github.com/matrixorigin/matrixone) database driver for Laravel. Use MatrixOne as a drop-in Laravel database: Eloquent, Query Builder, Schema Builder, migrations, transactions and Laravel's own testing traits — plus vector search.

## Features

- **`matrixone` driver** built on Laravel's MySQL stack — read/write splitting, reconnects and lazy connections work like a built-in driver
- **Eloquent & Query Builder** — relationships, eager loading, soft deletes, upserts, JSON columns, full-text search, pagination
- **Schema Builder & migrations** — `migrate`, `migrate:fresh`, `db:wipe`, `db:show` and schema introspection adapted to MatrixOne's catalog
- **Real transactions** — `RefreshDatabase`, `DatabaseTransactions` and `DatabaseTruncation` work unchanged
- **Vector search** — `vecf32` / `vecf64` columns, IVF-Flat and HNSW indexes, an `AsVector` cast and nearest-neighbour queries
- **MatrixOne-aware** — works around MatrixOne quirks and fails clearly on unsupported features
- PHP 8.2+, Laravel 12 and 13, MatrixOne 4.2+

## Installation

```bash
composer require vuthaihoc/laravel-matrixone
```

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
        'strict' => true,
    ],
],
```

Set `DB_CONNECTION=matrixone` to make it the default connection. See [Installation](docs/docs/installation.md) for every option.

## Running MatrixOne

Ready-to-use Docker setups live in [`docker/`](docker):

```bash
# Standalone, data on local disk (./mo-data)
cd docker/standalone && docker compose up -d

# Standalone, table data on S3 / MinIO: edit docker/s3/etc/*.toml first
cd docker/s3 && docker compose up -d
```

Connect on `127.0.0.1:6001` as `root` / `111`. See [Running MatrixOne with Docker](docs/docs/docker.md). For clusters, Kubernetes and other deployments, see the [MatrixOne documentation](https://docs.matrixorigin.cn/mo/en/latest/).

## Quick start

```php
// Migrations: the MatrixOne blueprint adds vector helpers.
use MatrixOne\Schema\Blueprint;

Schema::create('documents', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->vector('embedding', 3);
    $table->vectorIndex('embedding');
    $table->timestamps();
});

// Models are plain Eloquent models.
use Illuminate\Database\Eloquent\Model;
use MatrixOne\Eloquent\Casts\AsVector;

class Document extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['embedding' => AsVector::class];
    }
}

Document::create(['title' => 'MatrixOne', 'embedding' => [0.1, 0.2, 0.3]]);

// The 5 nearest documents by cosine distance.
Document::nearestTo('embedding', [0.1, 0.2, 0.25], 5)->get();

// Laravel's own vector methods work too.
Document::whereVectorSimilarTo('embedding', [0.1, 0.2, 0.25], minSimilarity: 0.8)->get();
```

## Documentation

| Page | Content |
|------|---------|
| [Installation](docs/docs/installation.md) | Requirements and configuration |
| [Docker](docs/docs/docker.md) | Standalone and S3-backed MatrixOne servers |
| [Query Builder](docs/docs/query-builder.md) | Behaviour differences, JSON, full-text and vector queries |
| [Eloquent](docs/docs/eloquent.md) | Models, the `AsVector` cast, transactions |
| [Full-text Search](docs/docs/full-text.md) | Parsers, relevance, session variables, FULLTEXT2 |
| [Schema](docs/docs/schema.md) | Column types, indexes, vector indexes, introspection |
| [Testing](docs/docs/testing.md) | Laravel testing traits on MatrixOne |
| [Compatibility](docs/docs/compatibility.md) | Every MatrixOne difference the driver handles or rejects |

## Testing

```bash
(cd docker/standalone && docker compose up -d)   # MatrixOne 4.2.4 on 127.0.0.1:6001 (root / 111)
composer test
```

## Credits

Based on [laravel-clickhouse](https://github.com/laravel-clickhouse/laravel-clickhouse).

## License

MIT. See [LICENSE](LICENSE).
