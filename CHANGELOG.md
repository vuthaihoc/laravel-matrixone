# Changelog

## Unreleased

### Changed

- The `matrixone` driver no longer runs `use <database>` after connecting: `use_db_after_connecting` now defaults to `false`. MatrixOne refuses `use` to ordinary users ("do not have privilege to execute the statement") even when their role owns the database, and the DSN already selects it. Set `'use_db_after_connecting' => true` to restore the old behaviour. Applications that set it to `false` as a workaround can drop the option.

### Fixed

- `Schema::getForeignKeys()` works for users without access to the system catalogs. Foreign keys are read from `SHOW CREATE TABLE` instead of `mo_catalog.mo_foreign_keys`, so no `grant select on table mo_catalog.mo_foreign_keys` is needed. Composite keys keep their column order, `on_update` / `on_delete` report `set null` and `no action` (previously `set_null` / `no_action`), and a missing table or a view returns `[]` as on MySQL.
