# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A Laravel database driver for MatrixOne (MySQL wire protocol, default port 6001). It registers the `matrixone` driver and builds on Laravel's MySQL connection, connector and grammars, overriding only where MatrixOne differs. Supports PHP 8.2+, Laravel 12 and 13, MatrixOne 4.2+.

## Development Commands

- `composer test` — PHPUnit (unit + feature). Feature tests need MatrixOne on 127.0.0.1:6001 (`cd docker/standalone && docker compose up -d`). Docker setups for a standalone and an S3-backed server live in `docker/`, documented in `docs/docs/docker.md`.
- `composer test:unit` / `composer test:feature`
- `composer test:known-issues` — `tests/KnownIssues`: open MatrixOne bugs in plain SQL, asserting MySQL behaviour. Excluded from `composer test`; failures are expected until MatrixOne fixes them.
- `vendor/bin/phpunit --filter TestName` — a single test
- `composer phpstan` — PHPStan level 9
- `composer cs` / `composer cs:fix` — Laravel Pint
- `cd docs && npx vitepress build` (or `bun run build`) — documentation site

Local runs may use a gitignored `phpunit.xml` (copied from `phpunit.xml.dist`) to override the `MATRIXONE_*` env vars. Feature tests create the `laravel_matrixone_test` database automatically.

## Architecture

- `src/MatrixOneServiceProvider.php` — binds `db.connector.matrixone` and `Connection::resolverFor('matrixone')`, so Laravel's ConnectionFactory builds the connection like a built-in driver.
- `src/MatrixOneConnection.php` — extends `MySqlConnection`: grammars, processor, unique-violation detection, `getMatrixOneVersion()`, and dropping connections broken by a server panic (`handleQueryException`).
- `src/Connectors/MatrixOneConnector.php` — extends `MySqlConnector`; emulated prepares on by default (`emulate_prepares` option); applies the `variables` option with `SET SESSION`.
- Session variables at runtime: `MatrixOneConnection::setSessionVariables()`, `getSessionVariables()`, `withSessionVariables()`.
- `src/Query/Grammar.php` — MySQL grammar overrides (exists, upsert, locks, RAND, savepoints off, JSON, DELETE safeguard, vector distance).
- `src/Query/Builder.php` — truncate fallback, full-text relevance helpers (`searchFullText`, `selectFullTextRelevance`, `orderByFullTextRelevance`), vector helpers (`nearestTo`, `*VectorDistanceUsing`) and overrides of Laravel's vector methods.
- `src/Query/Processors/MatrixOneProcessor.php` — normalizes column/index metadata.
- `src/Schema/Grammar.php`, `Builder.php` — DDL and introspection overrides, vector columns and indexes. Blueprint additions (`vector64`, and `vectorIndex`/`dropVectorIndex` on Laravel versions lacking them) are macros registered in `MatrixOneServiceProvider::registerBlueprintMacros()`; do not reintroduce a Blueprint subclass.
- `src/Eloquent/Casts/AsVector.php`, `src/Support/Vector.php` — vector literal conversion.

`resources/boost/` holds the Laravel Boost guideline and the `matrixone-development` skill shipped to applications; update them together with `docs/docs/compatibility.md` whenever a pitfall or workaround changes.

`docs/docs/compatibility.md` lists every MatrixOne difference the driver handles; keep it in sync when adding an override, and prove each override with a feature test against a real server.

## MatrixOne pitfalls

- Boolean expressions return the strings `"true"`/`"false"`; `(bool) "false"` is true in PHP. Any grammar query whose result is cast to bool must return an integer (`if(expr, 1, 0)`).
- No savepoints; nested transactions are flattened.
- An unconditional `DELETE` with `foreign_key_checks = 0` corrupts FK metadata — keep the `where 1 = 1` safeguard.
- 4.2.4: inserting into a table with both a foreign key and a FULLTEXT index panics.
- 4.2.4: a correlated `count(*)` with an extra predicate, selected by a single primary key (`loadCount()` on one soft-deletable model), panics and leaves the connection mid-response. Tests that trigger it must not share a connection with later tests.
- `LAST_INSERT_ID()` is wrong on tables with a FULLTEXT index; `insertGetId()` must keep using `insert ... returning`.
- Every `SET` assignment of an UPDATE reads the original row: never emit two assignments to one column (JSON path updates are merged into one `json_set()`).
- `LIKE` and `=` ignore `_ci` collations; the grammar maps `like` to `ilike`, `=` stays case-sensitive.
- JSON columns accept neither defaults nor indexes; the grammar throws unless `ignore_json_defaults` / `ignore_json_indexes` is set.

## Code Comments Language

- All code (`src/`, `tests/`) — comments and docblocks MUST be in English.
- Public docs (`docs/`, `README.md`) — English.

## Release Checklist

1. `composer cs`, `composer phpstan` and `composer test` pass with zero errors (MatrixOne running locally).
2. `composer.json`, `LICENSE` and `README.md` are accurate; no placeholder text or broken links.
3. `cd docs && npx vitepress build` succeeds; `docs/.vitepress/config.ts` base matches the GitHub Pages path (`/laravel-matrixone/`).
4. Working tree clean and pushed.
5. `git tag vX.Y.Z && git push origin vX.Y.Z` — `.github/workflows/release.yml` creates the GitHub release.
