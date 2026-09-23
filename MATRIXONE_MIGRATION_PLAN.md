# Kế hoạch chuyển `laravel-clickhouse` thành driver Laravel cho MatrixOne

> Trạng thái: đã triển khai trên nhánh `feature/matrixone-driver` (2026-09-23). Tài liệu hiện hành nằm trong `docs/`; CI đã bỏ theo yêu cầu. File này giữ lại làm lịch sử quyết định.
> Mục tiêu: package `laravel-matrixone` là một Laravel database driver (`driver => 'matrixone'`)
> dùng được như một connection bình thường của Laravel: Eloquent, Query Builder, Schema Builder,
> migrations, transactions, testing traits chuẩn của framework.

## 0. Quyết định kiến trúc

**MatrixOne nói giao thức MySQL 8.0 (port 6001, `root`/`111`), không phải HTTP như ClickHouse.**
Vì vậy toàn bộ tầng HTTP client hiện tại (`src/Client/*`, Guzzle/Curl transports, `Parallel`,
`Escaper`, `JsonEachRowEncoder`, `Format`) sẽ bị bỏ. Driver mới xây trên PDO MySQL và kế thừa
stack MySQL sẵn có của Laravel:

| Lớp | Hiện tại (ClickHouse) | Mới (MatrixOne) |
|---|---|---|
| Connection | `Illuminate\Database\Connection` + HTTP client tự viết | `extends Illuminate\Database\MySqlConnection` |
| Connector | không có (client tự kết nối) | `extends Illuminate\Database\Connectors\MySqlConnector` |
| Query Grammar | `Query\Grammar` viết lại từ base Grammar | `extends Query\Grammars\MySqlGrammar` |
| Query Builder | 1.200 dòng mở rộng ClickHouse | `extends Query\Builder`, chỉ thêm API MatrixOne |
| Schema Grammar | 1.188 dòng, ENGINE/ORDER BY/PARTITION | `extends Schema\Grammars\MySqlGrammar`, override chỗ khác biệt |
| Schema Builder | tự viết `dropAllTables`... | `extends Schema\MySqlBuilder` |
| Eloquent Model | `$incrementing = false`, builder riêng | dùng Eloquent chuẩn; chỉ thêm cast `AsVector` |
| Migration repository | bảng riêng không có `id` | dùng repository chuẩn của Laravel |
| Testing traits | thay thế vì không có transaction | bỏ; `RefreshDatabase` chuẩn hoạt động vì MatrixOne có ACID transaction |

Lý do chọn kế thừa MySQL stack thay vì viết lại: ~90% SQL Laravel sinh ra cho MySQL chạy được
trên MatrixOne. Package chỉ cần là "lớp mỏng" xử lý các khác biệt và thêm tính năng riêng
(vector, fulltext, snapshot).

## 1. Những khác biệt MatrixOne so với MySQL cần xử lý

Nguồn: docs.matrixorigin.cn (Reference > SQL > Limitations / Data Types / System Variables /
System Tables, cập nhật 21-22/09/2026) và các issue tương thích trên GitHub `matrixorigin/matrixone`.
Mục nào ghi **[probe]** phải kiểm chứng bằng script ở bước 2 trước khi code.

### 1.1 Kết nối / session
- `SET NAMES`, `time_zone`, `sql_mode`, `autocommit`, `foreign_key_checks` tồn tại.
  `sql_mode` chỉ thực thi `ONLY_FULL_GROUP_BY`, các mode khác chỉ chấp nhận về cú pháp
  [probe: `SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,...'` có lỗi không].
- `SET SESSION TRANSACTION ISOLATION LEVEL ...` [probe].
- Version string trả về qua `PDO::ATTR_SERVER_VERSION` [probe: dạng `8.0.30-MatrixOne-v4.2.x`?].
  `MySqlConnector::getSqlMode()` dùng `version_compare` trên chuỗi này.
- Prepared statement: server-side có hỗ trợ nhưng có bug metadata cũ sau `ALTER TABLE`
  (issue #29180) và cắt bớt kết quả khi trộn bind param với `IN (...)` literal trên
  `information_schema.columns` (issue #27912, v4.2.0). Mặc định nên bật
  `PDO::ATTR_EMULATE_PREPARES => true` và cho phép tắt qua config [probe cả hai chế độ].

### 1.2 DDL
- Không có `SET` type; không có spatial (`GEOMETRY`/`GEOGRAPHY`); `INVISIBLE` column [probe];
  stored procedure/trigger/event không đảm bảo.
- `PARTITION BY`: chỉ KEY/HASH, RANGE/LIST chưa có; `ALTER TABLE ... PARTITION` không hỗ trợ.
- `KEY ... USING BTREE` bị từ chối (issue #29220) → không sinh `algorithm` cho index.
- `CHARACTER SET utf32` bị từ chối; workflow chuẩn là `utf8mb4`
  [probe: `DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` mà Laravel sinh ra].
- Không gộp `ADD COLUMN` + `ADD INDEX` trong một `ALTER TABLE` (issue #28052) → mỗi command
  một statement (Laravel MySQL grammar đã tách sẵn, nhưng `compileAdd` gộp nhiều cột: [probe]).
- `'0000-00-00 00:00:00'` bị từ chối → không dùng làm default.
- `ALTER TABLE RENAME COLUMN`, `MODIFY`, `CHANGE`, `RENAME INDEX`, `DROP FOREIGN KEY`,
  `AUTO_INCREMENT = n`, `COMMENT`, `ON UPDATE CURRENT_TIMESTAMP`, generated columns
  (`VIRTUAL`/`STORED`) [probe từng cái].
- Kiểu riêng: `vecf32(n)`, `vecf64(n)` (không được làm PK/unique), `UUID`, `DATALINK`,
  `FULLTEXT` / `FULLTEXT2 ... WITH PARSER ngram`, vector index `USING ivfflat` / `hnsw`.
- Multi-statement DDL đọc cột vừa rename có bug (issue #29099) → luôn chạy từng statement.

### 1.3 Introspection (`Schema::getTables/getColumns/getIndexes/getForeignKeys`)
- `information_schema` là **tập con**; một số cột luôn rỗng. Laravel MySQL grammar dùng
  `data_length`, `index_length`, `engine`, `table_collation`, `table_comment`,
  `generation_expression`, `extra`, `collation_name`, `information_schema.statistics`,
  `key_column_usage` + `referential_constraints` [probe từng query].
- FK metadata trong `information_schema.table_constraints` không nhất quán (issue #29001)
  → fallback sang `mo_catalog.mo_foreign_keys` / `mo_catalog.mo_indexes` nếu cần.

### 1.4 DML / query
- `INSERT ... ON DUPLICATE KEY UPDATE`, `INSERT IGNORE`, `REPLACE`, `lastInsertId()` [probe].
- Laravel `compileUpsert` dùng `INSERT ... AS laravel_upsert_alias` khi server >= 8.0.19
  → MatrixOne có thể không hiểu alias; override dùng dạng `VALUES(col)` [probe].
- JSON: có `JSON_EXTRACT`, `JSON_EXTRACT_STRING`, `JQ`; Laravel dùng
  `json_unquote(json_extract(...))`, `json_contains`, `json_length`, `json_overlaps`,
  `->`/`->>` [probe từng hàm].
- `SELECT ... FOR UPDATE`, `LOCK IN SHARE MODE` [probe]. `LOCK TABLES` không giả định có.
- Window function (`row_number() over`) cho `groupLimit` [probe]. `SHOW STATUS LIKE 'Threads_connected'`
  (`compileThreadCount`) [probe, có thể trả null].
- `MATCH ... AGAINST (... IN BOOLEAN MODE)` có; MatrixOne rewrite MATCH không hoạt động trong
  subquery/window (issue #29079, #28974) → ghi vào docs.
- Reserved keywords khác MySQL một chút → luôn wrap identifier bằng backtick (grammar đã làm).

## 2. Bước 0 — Môi trường và script kiểm chứng tương thích

1. Thêm `docker-compose.yml` (dev) và service container trong CI:
   ```yaml
   services:
     matrixone:
       image: matrixorigin/matrixone:4.2.4   # release 17/09/2026
       ports: ["6001:6001"]
       healthcheck:
         test: ["CMD", "mysql", "-h127.0.0.1", "-P6001", "-uroot", "-p111", "-e", "select 1"]
   ```
2. Viết `tests/probe/compatibility.php` (không commit vào testsuite chính) chạy lần lượt
   mọi câu SQL mà Laravel MySQL grammar sinh ra cho các mục **[probe]** ở trên, in
   OK/FAIL kèm thông báo lỗi. Kết quả quyết định danh sách override ở bước 4-5.
3. Chốt phiên bản hỗ trợ: MatrixOne >= 4.2 (ghi vào README).

Đầu ra: bảng "MatrixOne compatibility matrix" đưa vào `docs/docs/compatibility.md`.

## 3. Bước 1 — Đổi tên package và bộ khung

- `composer.json`: name `vuthaihoc/laravel-matrixone` (xác nhận với chủ repo, remote
  hiện vẫn trỏ `laravel-clickhouse/laravel-clickhouse`), description, keywords, homepage,
  autoload `MatrixOne\\` → `src/`, bỏ `smi2/phpclickhouse` và `guzzlehttp/guzzle`,
  thêm `ext-pdo_mysql`. Provider: `MatrixOne\Laravel\MatrixOneServiceProvider`.
- Namespace gốc `ClickHouse\` → `MatrixOne\` trên toàn bộ `src/` và `tests/`.
- Driver key: `'driver' => 'matrixone'`. Provider đăng ký qua `Connection::resolverFor('matrixone', ...)`
  và `$db->extend('matrixone', ...)`, tự tạo PDO qua connector (không cần
  `beforeResolving(MigrateInstallCommand)` nữa).
- Config mẫu:
  ```php
  'matrixone' => [
      'driver'   => 'matrixone',
      'host'     => env('MO_HOST', '127.0.0.1'),
      'port'     => env('MO_PORT', 6001),
      'database' => env('MO_DATABASE', 'laravel'),
      'username' => env('MO_USERNAME', 'root'),
      'password' => env('MO_PASSWORD', '111'),
      'charset'  => 'utf8mb4',
      'collation'=> 'utf8mb4_bin',       // hoặc bỏ nếu probe cho thấy MatrixOne không nhận COLLATE
      'prefix'   => '',
      'strict'   => false,               // sql_mode chỉ có ONLY_FULL_GROUP_BY
      'emulate_prepares' => true,
      'options'  => [],
  ],
  ```
- Xoá: `src/Client/`, `src/Enums/Format.php`, `src/Support/*`, `src/Laravel/Parallel.php`,
  `src/Exceptions/ParallelQueryException.php`, `src/Laravel/Testing/*`,
  `src/Laravel/Migrations/*`, `src/Laravel/Eloquent/Builder.php`, các test tương ứng.

## 4. Bước 2 — Connection và Connector

`src/Laravel/Connection.php extends MySqlConnection`:
- `getDriverTitle()` → `'MatrixOne'`; `isMaria()` → `false`.
- `getServerVersion()` tách số phiên bản MatrixOne từ chuỗi server (theo kết quả probe);
  thêm `getMatrixOneVersion()`.
- `isUniqueConstraintError()` theo mã lỗi/thông điệp thật của MatrixOne (probe `Duplicate entry`).
- `getSchemaState()` ném `RuntimeException` (không có `mysqldump`); có thể bổ sung
  `mo-dump` ở phiên bản sau.
- `getSchemaBuilder()` / `getDefaultQueryGrammar()` / `getDefaultSchemaGrammar()` trả lớp của package.
- Giữ tương thích Laravel 11/12/13 như hiện tại (`makeGrammar()` với `setConnection`).

`src/Laravel/Connectors/MatrixOneConnector.php extends MySqlConnector`:
- `configureConnection()`: chỉ set `NAMES`/`time_zone`; `sql_mode` chỉ khi người dùng cấu hình
  `modes` (không suy ra từ `strict` + version như MySQL); bọc từng `SET` riêng để một biến không
  hỗ trợ không làm hỏng kết nối.
- `getOptions()`: mặc định `PDO::ATTR_EMULATE_PREPARES` theo config `emulate_prepares`.
- Bỏ `use \`db\`` sau connect nếu probe cho thấy DSN `dbname=` đã đủ.

## 5. Bước 3 — Query Grammar và Builder

`src/Laravel/Query/Grammar.php extends MySqlGrammar`: override theo probe, dự kiến
- `compileUpsert()` → dạng `ON DUPLICATE KEY UPDATE col = VALUES(col)`.
- `compileThreadCount()` → `null` nếu `SHOW STATUS` không có.
- JSON selector (`wrapJsonSelector`, `compileJsonContains`...) nếu hàm khác tên.
- `compileLock()` giữ nguyên nếu `FOR UPDATE` chạy được.
- Thêm: `compileVectorDistance()` cho `l2_distance`, `cosine_similarity`, `inner_product`;
  `compileSnapshot()` cho `{SNAPSHOT = 'name'}` (MatrixOne time travel) nếu muốn.

`src/Laravel/Query/Builder.php extends Illuminate\Database\Query\Builder`: chỉ thêm API riêng
- `orderByVectorDistance(column, vector, metric = 'l2')`, `selectVectorDistance(...)`,
  `whereVectorDistance(...)`.
- `whereFullText()` đã có sẵn ở base (MySQL) — kiểm chứng `IN BOOLEAN MODE`/`NATURAL LANGUAGE`.
- `snapshot(string $name)` (tuỳ chọn, phase sau).

Bỏ toàn bộ: `final`, `prewhere`, `sample`, `limitBy`, `arrayJoin`, `settings`, `cluster`,
`whereGlobalIn`, `whereEmpty`, các join ANY/SEMI/ANTI/ASOF, `withQuery*` (Laravel có sẵn
CTE qua package khác; base builder không có `with` — có thể giữ `withQuery()` nếu MatrixOne
hỗ trợ CTE, probe), `insertUsingFormat`, `delete(lightweight, partition)`.

## 6. Bước 4 — Schema Grammar, Builder, Blueprint

`src/Laravel/Schema/Grammar.php extends Schema\Grammars\MySqlGrammar`:
- Kiểu: `typeSet()` ném `RuntimeException('SET is not supported by MatrixOne')`;
  `typeGeometry/typeGeography` ném lỗi; `typeVector()` → `vecf32(n)`; thêm `typeVectorF64()`;
  `typeUuid()` → `uuid` native (thay vì `char(36)`); `typeJsonb()` → `json`.
- Modifier: `modifyInvisible()` bỏ qua/ném lỗi theo probe; `modifyCharset/Collate` chỉ cho phép
  `utf8mb4`.
- `compileKey()` không sinh `USING <algorithm>`; `compileFullText()` hỗ trợ `FULLTEXT`/`FULLTEXT2`
  + `WITH PARSER`; thêm `compileVectorIndex()` (`ivfflat` với `lists`, `hnsw` với `m`/`ef_construction`).
- `compileCreateEncoding()` / `compileCreateEngine()` theo probe (bỏ `ENGINE = InnoDB` nếu bị từ chối).
- `compileAdd()` tách mỗi cột một `ALTER TABLE` nếu probe cho thấy MatrixOne không nhận nhiều `ADD`.
- `compileRenameIndex()` → drop + create nếu `RENAME INDEX` không hỗ trợ.
- `compileTables/compileColumns/compileIndexes/compileForeignKeys` viết lại theo cột thực có
  trong `information_schema` của MatrixOne, fallback `mo_catalog` cho FK.
- `compileDropAllTables/Views` giữ (chạy trong `SET FOREIGN_KEY_CHECKS=0`).

`src/Laravel/Schema/Blueprint.php extends Illuminate\Database\Schema\Blueprint`:
- `vector(string $column, int $dimensions, string $type = 'f32')`, `vectorIndex(...)`,
  `fullText(...)` mở rộng tham số parser, `snapshot`-related không cần.
- Xoá `engine('Memory')`, `partitionBy`, `orderBy`, `array`, `lowCardinality`, `sync`, `granularity`
  (`ColumnDefinition`, `CommandDefinition`, `IndexDefinition` chỉ giữ nếu còn tham số riêng:
  `IndexDefinition::lists()/m()/efConstruction()` cho vector index, `ColumnDefinition` cho `vecf64`).
- `BlueprintLaravelCompatibility` giữ (khác biệt constructor Laravel 11 vs 12).

`src/Laravel/Schema/Builder.php extends MySqlBuilder`: bỏ `dropSync/dropIfExistsSync`,
`enable/disableForeignKeyConstraints` dùng của base.

## 7. Bước 5 — Eloquent

- Bỏ `Eloquent\Builder`; `Eloquent\Model` giữ như lớp tiện ích tuỳ chọn (không ép
  `$incrementing = false` nữa; có thể đặt `$connection = 'matrixone'` mặc định).
- Thêm `MatrixOne\Laravel\Eloquent\Casts\AsVector` (array PHP <-> literal `'[0.1, 0.2]'`).
- Thêm scope/macro ví dụ `nearest($column, $vector, $limit)` trong docs.

## 8. Bước 6 — Tests

- `tests/Unit`: viết lại `GrammarTest`, `Schema/GrammarTest`, `ConnectionTest`, `BuilderTest`
  cho các override và API mới (mock connection như `tests/Unit/TestCase.php` hiện có).
- `tests/Feature` (Orchestra Testbench, MatrixOne thật):
  - Migrations chuẩn của Laravel chạy `migrate`, `migrate:rollback`, `migrate:fresh`, `db:wipe`.
  - `RefreshDatabase`, `DatabaseTransactions`, `DatabaseTruncation`, `DatabaseMigrations` của
    framework hoạt động **không cần trait riêng** (đây là tiêu chí "thay thế được Laravel database").
  - Introspection: `getTables/getColumns/getIndexes/getForeignKeys` trả đúng shape.
  - CRUD/Eloquent: insert + `insertGetId`, upsert, `updateOrCreate`, relationships, soft deletes,
    JSON where, fulltext, vector nearest, transaction rollback, unique-violation exception.
  - Chạy thêm bộ test "Laravel-like": copy vài migration mặc định của Laravel (`users`, `cache`,
    `jobs`, `sessions`) và chạy trên MatrixOne — đây là bằng chứng drop-in.
- `phpunit.xml.dist`: env `MATRIXONE_HOST/PORT/DATABASE/USERNAME/PASSWORD` (127.0.0.1:6001, root/111).
- CI `tests.yml`: service `matrixorigin/matrixone:<tag>` + healthcheck chờ port 6001; ma trận
  PHP 8.2-8.5 × Laravel 11/12/13 × MatrixOne 4.2.x (và bản mới nhất).

## 9. Bước 7 — Docs, README, CLAUDE.md, release

- Viết lại `README.md`, `docs/` (installation, query-builder, eloquent, schema, testing,
  compatibility, vector-search, fulltext), đổi `docs/.vitepress/config.ts` (title, base
  `/laravel-matrixone/`, links). Bỏ `parallel-queries.md`.
- Cập nhật `CLAUDE.md` (kiến trúc mới, cổng 6001, lệnh test, checklist release).
- `.github/workflows/*`, `pint.json`, `phpstan.neon` giữ, chỉ đổi đường dẫn/namespace.
- Gắn tag `v1.0.0` cho package mới (breaking hoàn toàn so với laravel-clickhouse).

## 10. Thứ tự thực hiện và ước lượng

| # | Việc | Phụ thuộc | Ước lượng |
|---|---|---|---|
| 0 | Docker + probe script, chốt compatibility matrix | – | 0.5 ngày |
| 1 | Rename/skeleton, xoá code ClickHouse, composer | 0 | 0.5 ngày |
| 2 | Connection + Connector | 1 | 0.5 ngày |
| 3 | Query Grammar/Builder + unit tests | 0, 2 | 1 ngày |
| 4 | Schema Grammar/Builder/Blueprint + unit tests | 0, 2 | 1.5 ngày |
| 5 | Eloquent + AsVector | 3 | 0.5 ngày |
| 6 | Feature tests + CI | 2-5 | 1 ngày |
| 7 | Docs/README/CLAUDE.md, release | 6 | 0.5 ngày |

Mỗi bước kết thúc bằng `composer cs`, `composer phpstan`, `composer test` xanh.

## 11. Rủi ro và điểm cần chủ repo quyết định

1. **Tên package / remote GitHub**: thư mục là `laravel-matrixone` nhưng remote vẫn là
   `laravel-clickhouse/laravel-clickhouse`. Cần repo mới hoặc đổi tên repo.
2. **Giữ lại tính năng ClickHouse hay không**: kế hoạch này bỏ hẳn (package mới, không đa driver).
3. **Prepared statements**: chọn mặc định emulate để tránh bug server-side; cần xác nhận sau probe.
4. **Introspection**: nếu `information_schema` thiếu cột, phần `Schema::getTables()` sẽ trả `size`,
   `engine`, `collation` là `null`; ghi rõ trong docs.
5. **Laravel 11 EOL**: cân nhắc chỉ hỗ trợ Laravel 12+ để bớt nhánh tương thích grammar.
