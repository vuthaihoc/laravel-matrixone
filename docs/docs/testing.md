# Testing

MatrixOne supports real transactions, so Laravel's testing traits work without any package-specific replacement:

| Trait | Works | Notes |
|-------|-------|-------|
| `RefreshDatabase` | ✓ | Recommended. Each test runs inside a rolled-back transaction. |
| `DatabaseTransactions` | ✓ | |
| `DatabaseTruncation` | ✓ | Tables referenced by foreign keys are emptied with `DELETE`, since MatrixOne refuses to truncate them. |
| `DatabaseMigrations` | ✓ | |

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class PostTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_posts(): void
    {
        Post::factory()->create();

        $this->assertDatabaseCount('posts', 1);
    }
}
```

Point the test environment at a dedicated database in `phpunit.xml`:

```xml
<env name="DB_CONNECTION" value="matrixone"/>
<env name="DB_DATABASE" value="laravel_test"/>
```

::: warning Nested transactions in tests
`RefreshDatabase` wraps each test in a transaction, so every `DB::transaction()` in your code becomes a nested transaction. MatrixOne has no savepoints: a failing inner transaction rethrows as usual, but its writes are only discarded when the test's outer transaction is rolled back at the end of the test. See [Eloquent › Transactions](./eloquent#transactions).
:::

## Running this package's test suite

```bash
composer test               # Unit + Feature: the Laravel database driver
composer test:monitoring    # statement history and slow queries (slow)
composer test:all           # Unit + Feature + Monitoring
composer test:known-issues  # open MatrixOne bugs, expected to fail (see Compatibility)
```

The `Monitoring` suite waits for MatrixOne to publish statements to `system.statement_info` (a few seconds each), so it is kept out of `composer test`. Run it when changing `statementLog()`, `tableStats()` or `matrixone:slow-queries`, and before a release.

The `KnownIssues` suite is excluded from `composer test`; each test asserts MySQL's behaviour, so a passing test means a MatrixOne release fixed that issue.


```bash
(cd docker/standalone && docker compose up -d)   # MatrixOne 4.2.4 on port 6001
composer test                 # unit + feature tests
```

Override the connection with the `MATRIXONE_HOST`, `MATRIXONE_PORT`, `MATRIXONE_DATABASE`, `MATRIXONE_USERNAME` and `MATRIXONE_PASSWORD` environment variables (for example in a local `phpunit.xml`).
