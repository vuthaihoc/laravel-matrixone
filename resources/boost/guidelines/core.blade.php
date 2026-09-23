## MatrixOne (vuthaihoc/laravel-matrixone)

- This application uses MatrixOne through the `matrixone` database driver. MatrixOne speaks the MySQL protocol but is not MySQL; write standard Laravel code and follow these rules. Activate the `matrixone-development` skill before designing schemas or writing non-trivial queries for a `matrixone` connection.
- Never put a FULLTEXT index on a table that has its own foreign key: inserts crash MatrixOne 4.2.4. Keep searchable text in a table without foreign keys, or drop the constraint.
- String `=` comparisons and unique indexes are case-sensitive even on `_ci` collations. Store e-mails and usernames with the `MatrixOne\Eloquent\Casts\Lowercase` cast and lower-case user input before querying or calling `Auth::attempt()`; use `whereIgnoreCase()` only when the data cannot be normalized.
- JSON columns cannot have default values or indexes. Set JSON defaults in the model (`$attributes`) and index a separate scalar column instead.
- There are no savepoints: a nested `DB::transaction()` does not roll back on its own; only the outermost transaction really rolls back.
- Not available: generated columns (`virtualAs`/`storedAs`), expression indexes, `set`/spatial columns, lateral joins, full-text query expansion, indexes on TEXT columns without a prefix length.
- Use `whereFullText()`, `searchFullText()` and the vector helpers (`vector()` columns, `nearestTo()`, `whereVectorSimilarTo()`) instead of raw `MATCH ... AGAINST` or distance SQL.
- Avoid raw `SELECT LAST_INSERT_ID()` and raw boolean expressions cast to PHP booleans; use `insertGetId()` / Eloquent keys and `exists()`.
