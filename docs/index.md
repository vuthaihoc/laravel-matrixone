---
layout: home

hero:
  name: Laravel MatrixOne
  text: MatrixOne Driver for Laravel
  tagline: Use MatrixOne as a drop-in Laravel database — Eloquent, Query Builder, Schema Builder, migrations and transactions.
  actions:
    - theme: brand
      text: Get Started
      link: /docs/installation
    - theme: alt
      text: View on GitHub
      link: https://github.com/vuthaihoc/laravel-matrixone

features:
  - title: Drop-in driver
    details: A `matrixone` driver built on Laravel's MySQL stack. Read/write splitting, reconnects and lazy connections work like any built-in driver.
  - title: Eloquent & Query Builder
    details: Relationships, eager loading, soft deletes, upserts, JSON columns, full-text search and pagination on MatrixOne.
  - title: Schema & Migrations
    details: Standard `artisan migrate`, `migrate:fresh` and `db:wipe`, with schema introspection adapted to MatrixOne's catalog.
  - title: Real transactions
    details: MatrixOne is ACID, so Laravel's own RefreshDatabase and DatabaseTransactions testing traits work unchanged.
  - title: Vector search
    details: vecf32 / vecf64 columns, IVF-Flat and HNSW indexes, an AsVector cast and nearest-neighbour queries.
  - title: MatrixOne-aware
    details: Works around MatrixOne quirks (boolean results, savepoints, TRUNCATE with foreign keys) and fails clearly on unsupported features.
---
