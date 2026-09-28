## MatrixOne (vuthaihoc/laravel-matrixone)

- This application uses MatrixOne through the `matrixone` database driver. MatrixOne speaks the MySQL protocol but is not MySQL; write standard Laravel code and follow these rules. Activate the `matrixone-development` skill before designing schemas or writing non-trivial queries for a `matrixone` connection.
- Never put a FULLTEXT index on a table that has its own foreign key: inserts crash MatrixOne 4.2.4. Keep searchable text in a table without foreign keys, or drop the constraint.
- String `=` comparisons and unique indexes are case-sensitive even on `_ci` collations. Store e-mails and usernames with the `MatrixOne\Eloquent\Casts\Lowercase` cast and lower-case user input before querying or calling `Auth::attempt()`; use `whereIgnoreCase()` only when the data cannot be normalized.
- JSON columns cannot have default values or indexes. Set JSON defaults in the model (`$attributes`) and index a separate scalar column instead.
- There is no ROLLBACK TO SAVEPOINT: a nested `DB::transaction()` does not roll back on its own; only the outermost transaction really rolls back. Do not catch an inner transaction's exception and keep going; with `'nested_transactions' => 'rollback_only'` the outer commit then throws.
- Write conflicts and deadlocks roll back the whole transaction; the driver retries `DB::transaction()` up to `retry_attempts` times (default 3). Keep side effects that must not repeat (mail, HTTP calls, jobs) out of the callback or behind `afterCommit`.
- A FULLTEXT index needs a primary key on the table.
- Not available: expression indexes, `set`/spatial columns, lateral joins, full-text query expansion, indexes on TEXT columns without a prefix length.
- There is no fuzzy/trigram search; for autocomplete use `suggest()`, `whereStartsWith()` or `whereContains()`.
- Use `whereFullText()`, `searchFullText()` and the vector helpers (`vector()` columns, `nearestTo()`, `whereVectorSimilarTo()`) instead of raw `MATCH ... AGAINST` or distance SQL.
- Never select a correlated subquery with `limit` (e.g. `addSelect([... ->latest()->limit(1)])`): MatrixOne silently returns NULL for most rows. Use `latestOfMany()` / `ofMany()` relationships or aggregate subqueries.
- For analytics use the driver's `timeWindow()`, `sample()`, `asOfSnapshot()` / `asOfTimestamp()` and `clusterBy()` rather than raw MatrixOne syntax; `clusterBy()` requires a table without a primary key.
- Avoid raw `SELECT LAST_INSERT_ID()` and raw boolean expressions cast to PHP booleans; use `insertGetId()` / Eloquent keys and `exists()`.
