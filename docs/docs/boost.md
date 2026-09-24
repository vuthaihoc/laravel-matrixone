# AI Assistants (Laravel Boost)

MatrixOne speaks the MySQL protocol, so AI coding agents assume it behaves like MySQL. They add FULLTEXT indexes to tables with foreign keys, compare e-mails with `=` on a `_ci` collation, or give JSON columns a default. On MatrixOne these crash the server, return nothing, or fail the migration.

The package ships resources for [Laravel Boost](https://laravel.com/docs/boost) that teach agents such as Claude Code, Cursor, Codex, Gemini CLI, GitHub Copilot and Junie these differences and the patterns that work.

- [What is included](#what-is-included)
- [Installation](#installation)
- [Keeping the resources updated](#keeping-the-resources-updated)
- [Using the skill](#using-the-skill)
- [Customizing](#customizing)
- [Without Boost](#without-boost)

## What is included

| Resource | Path in the package | Loaded |
|----------|---------------------|--------|
| Guideline | `resources/boost/guidelines/core.blade.php` | Always, at the start of every agent session |
| `matrixone-development` skill | `resources/boost/skills/matrixone-development/SKILL.md` | On demand, when the task concerns MatrixOne |

The **guideline** is short. It holds only the rules an agent must never break:

- no FULLTEXT index on a table that has its own foreign key (MatrixOne 4.2.4 panics on insert);
- `=` comparisons and unique indexes are case-sensitive, so normalize with the `Lowercase` cast;
- JSON columns take no defaults or indexes;
- nested transactions do not roll back on their own (no savepoints);
- unsupported features: generated columns, expression indexes, lateral joins, query expansion…;
- never select a correlated subquery with `limit`, since MatrixOne silently returns NULL;
- use the driver's full-text and vector helpers instead of raw SQL.

The **skill** is the detailed reference an agent reads before designing a schema or writing a non-trivial query:

| Section | Covers |
|---------|--------|
| Connection | `config/database.php`, `emulate_prepares`, `variables`, `ignore_json_*` options |
| Schema design rules | Keys, foreign keys vs FULLTEXT, JSON, vector columns and indexes (IVF-Flat, HNSW) |
| Queries | Case sensitivity, `LIKE`, JSON methods, upserts, locks, full-text (`FullTextQuery`), vectors |
| Session variables | `variables` option, `withSessionVariables()`, BM25 |
| Laravel Scout | `matrixone` and `matrixone-index` engines, language behaviour, embeddings, semantic and hybrid search |
| Laravel Pulse and Telescope | The Pulse migration to publish |
| Cache, queue and sessions | Database drivers, queue workers without `SKIP LOCKED` |
| Transactions and tests | Flattened transactions, `RefreshDatabase`, `DatabaseTruncation` |
| Known server bugs | MatrixOne 4.2.4 bugs and how to avoid them |
| When something fails | Error messages and what they mean |

## Installation

Install Boost in the application (not in this package):

```bash
composer require laravel/boost --dev
php artisan boost:install
```

`boost:install` finds the MatrixOne guideline and skill in `vendor/vuthaihoc/laravel-matrixone`. When it asks which third-party packages to include, select `vuthaihoc/laravel-matrixone`. Then choose your agents. Boost writes the guideline into the agent's instruction file (`CLAUDE.md`, `AGENTS.md`, …) and installs the skill in the agent's skills directory.

If Boost was installed before this package, discover the new resources with:

```bash
php artisan boost:update --discover
```

## Keeping the resources updated

The guideline and skill change with the driver, for example when a MatrixOne release fixes a bug or the driver gains a workaround. Refresh them after upgrading the package:

```bash
php artisan boost:update
```

Or refresh them automatically after every `composer update`:

```json
{
    "scripts": {
        "post-update-cmd": [
            "@php artisan boost:update --ansi"
        ]
    }
}
```

The generated files (`CLAUDE.md`, `AGENTS.md`, the skills directories, `boost.json`) are rebuilt by these commands. You may add them to `.gitignore`.

## Using the skill

Agents activate `matrixone-development` on their own when a task mentions MatrixOne, a `matrixone` connection, migrations, full-text or vector search, or a MatrixOne error. You can also ask for it explicitly:

```text
Use the matrixone-development skill and add full-text search on articles.title and articles.body.
```

```text
Use the matrixone-development skill: why does User::where('email', $email)->first() return null?
```

```text
Use the matrixone-development skill to configure Scout with a separate MatrixOne search index for Product.
```

A well-guided agent will, for example:

- put the FULLTEXT index on a table without foreign keys, or move the searchable text to a separate table;
- store e-mails with the `Lowercase` cast instead of relying on a `_ci` collation;
- use `FullTextQuery::anyOf()` for a search box, because natural language mode matches too little on MatrixOne;
- set JSON defaults in the model's `$attributes` instead of the migration.

## Customizing

Boost lets you add your own guidelines and skills to the application:

- Application-specific MatrixOne conventions (which tables carry FULLTEXT indexes, which connection holds the search index, …) belong in [project rules](https://laravel.com/docs/boost#project-rules). Ask your agent to remember them, and Boost records them in `.ai/rules`.
- Extra guidelines go in `.ai/guidelines/*.blade.php` or `.md`.
- To replace the package's skill, create `.ai/skills/matrixone-development/SKILL.md`. Boost uses a custom skill instead of an installed one with the same name. Copy the package's skill first so you keep its content, and re-check it after upgrades.

## Without Boost

The resources are plain Markdown and Blade, so any agent can read them. Point it at them from your own instruction file:

```markdown
This application uses MatrixOne through the `matrixone` driver.
Follow vendor/vuthaihoc/laravel-matrixone/resources/boost/guidelines/core.blade.php,
and read vendor/vuthaihoc/laravel-matrixone/resources/boost/skills/matrixone-development/SKILL.md
before writing migrations or queries.
```
