<?php

namespace MatrixOne\Tests\KnownIssues;

/**
 * Last verified against MatrixOne 4.2.4: 12 failures (open issues) and 5
 * passes (issues fixed since 3.0.9, kept as regression checks).
 */
class KnownIssuesTest extends TestCase
{
    /**
     * Panic: "invalid memory address or nil pointer dereference".
     * Driver workaround: none; the docs advise not to combine both on one table.
     */
    public function testInsertIntoATableWithBothAForeignKeyAndAFullTextIndex(): void
    {
        $this->runSql(
            'create table fkft_parents (id bigint unsigned auto_increment primary key)',
            'create table fkft_children (id bigint unsigned auto_increment primary key, parent_id bigint unsigned not null, title varchar(255))',
            'alter table fkft_children add constraint fkft_children_parent_id_foreign foreign key (parent_id) references fkft_parents (id)',
            'alter table fkft_children add fulltext fkft_children_title_fulltext(title)',
            'insert into fkft_parents values ()',
        );

        $parent = $this->runSql('select max(id) as id from fkft_parents')[0]['id'];

        $this->runSql("insert into fkft_children (parent_id, title) values ({$parent}, 'hello world')");

        $this->assertSame(1, (int) $this->runSql("select count(*) as c from fkft_children where match(title) against('hello')")[0]['c']);
    }

    /**
     * Panic: "index out of range" — triggered by Eloquent's loadCount()/withCount()
     * on a single soft-deletable parent. It needs a primary-key point lookup
     * outside and an extra predicate inside the correlated count(*).
     * Driver workaround: broken connections are dropped so the panic does not
     * leave locks behind (BrokenConnectionTest).
     */
    public function testCorrelatedCountWithAPrimaryKeyPointLookup(): void
    {
        $this->runSql(
            'create table cc_users (id bigint unsigned auto_increment primary key)',
            'create table cc_posts (id bigint unsigned auto_increment primary key, user_id bigint unsigned not null, deleted_at timestamp null)',
            'insert into cc_users values (1)',
            'insert into cc_posts (user_id) values (1), (1)',
        );

        $rows = $this->runSql(
            'select id, (select count(*) from cc_posts where cc_users.id = cc_posts.user_id and cc_posts.deleted_at is null) as c '
            .'from cc_users where cc_users.id = 1'
        );

        $this->assertSame(2, (int) $rows[0]['c']);
    }

    /**
     * LAST_INSERT_ID() reports internal FULLTEXT row IDs.
     * Driver workaround: insertGetId() uses INSERT ... RETURNING.
     */
    public function testLastInsertIdOnATableWithAFullTextIndex(): void
    {
        $pdo = self::connect();
        $pdo->exec('create table lid_articles (id bigint unsigned auto_increment primary key, title varchar(255))');
        $pdo->exec('alter table lid_articles add fulltext lid_articles_title_fulltext(title)');

        foreach (['a', 'b', 'c'] as $title) {
            $pdo->exec("insert into lid_articles (title) values ('{$title}')");
            $id = $pdo->query('select last_insert_id()')->fetchColumn();

            $this->assertSame($title, $pdo->query("select title from lid_articles where id = {$id}")->fetchColumn(), 'LAST_INSERT_ID() returned a wrong ID.');
        }
    }

    /**
     * An unconditional DELETE on a referenced table with foreign_key_checks = 0
     * leaves stale foreign key metadata; the table can no longer be dropped.
     * Fixed in 4.2.4 (broken in 3.0.9). Driver workaround: `delete from t where 1 = 1`.
     */
    public function testUnconditionalDeleteWithForeignKeyChecksDisabled(): void
    {
        $this->runSql(
            'create table ud_parents (id int primary key)',
            'create table ud_children (id int primary key, parent_id int)',
            'alter table ud_children add constraint ud_children_parent_foreign foreign key (parent_id) references ud_parents (id)',
            'insert into ud_parents values (1)',
        );

        $this->runSql('set foreign_key_checks = 0', 'delete from ud_parents', 'set foreign_key_checks = 1');

        $this->runSql('drop table ud_children', 'drop table ud_parents');
        $this->addToAssertionCount(1);
    }

    /**
     * MySQL applies SET assignments left to right; MatrixOne reads the
     * original row for each one.
     * Driver workaround: JSON path updates of one column share one json_set().
     */
    public function testUpdateAssignmentsSeeEarlierAssignments(): void
    {
        $rows = $this->runSql(
            'create table ua_items (id int primary key, name varchar(20))',
            "insert into ua_items values (1, 'a')",
            "update ua_items set name = 'x', name = concat(name, 'y') where id = 1",
            'select name from ua_items',
        );

        $this->assertSame('xy', $rows[0]['name']);
    }

    /**
     * `_ci` collations are ignored: `=`, LIKE and unique indexes compare case-sensitively.
     * Driver workaround: like/whereLike() compile to ILIKE; `=` and unique indexes are not handled.
     */
    public function testCaseInsensitiveCollations(): void
    {
        $this->runSql(
            "create table ci_users (id int primary key, email varchar(100)) default character set utf8mb4 collate 'utf8mb4_unicode_ci'",
            "insert into ci_users values (1, 'Alice@Example.com')",
        );

        $this->assertCount(1, $this->runSql("select id from ci_users where email = 'alice@example.com'"), '`=` ignores the _ci collation.');
        $this->assertCount(1, $this->runSql("select id from ci_users where email like 'alice%'"), 'LIKE ignores the _ci collation.');

        $this->runSql('alter table ci_users add unique ci_users_email_unique(email)');

        try {
            self::connect()->exec("insert into ci_users values (2, 'ALICE@example.com')");
        } catch (\PDOException) {
            return;
        }

        $this->fail('The unique index accepted a value that differs only by case.');
    }

    /**
     * Comparing an extracted JSON boolean with SQL TRUE fails ("invalid argument operator cast").
     * Driver workaround: JSON booleans are compared as 'true' / 'false' text.
     */
    public function testJsonBooleanComparison(): void
    {
        $rows = $this->runSql(
            'create table jb_docs (id int primary key, data json)',
            "insert into jb_docs values (1, '{\"on\": true}'), (2, '{\"on\": false}')",
            "select id from jb_docs where json_extract(data, '$.on') = true",
        );

        $this->assertSame([['id' => 1]], $rows);
    }

    /**
     * ROLLBACK TO SAVEPOINT is not implemented and aborts the transaction.
     * Driver workaround: savepoints are disabled, nested transactions are flattened.
     */
    public function testRollbackToSavepoint(): void
    {
        $pdo = self::connect();
        $pdo->exec('create table sp_items (id int primary key)');
        $pdo->beginTransaction();
        $pdo->exec('insert into sp_items values (1)');
        $pdo->exec('savepoint sp1');
        $pdo->exec('insert into sp_items values (2)');

        try {
            $pdo->exec('rollback to savepoint sp1');
        } catch (\PDOException $e) {
            $this->fail('ROLLBACK TO SAVEPOINT failed: '.$e->getMessage());
        }

        $pdo->commit();
        $this->assertSame(1, (int) $pdo->query('select count(*) from sp_items')->fetchColumn());
    }

    /**
     * MySQL 8 row alias in INSERT ... ON DUPLICATE KEY UPDATE.
     * Driver workaround: upsert() uses VALUES(col).
     */
    public function testUpsertRowAlias(): void
    {
        $rows = $this->runSql(
            'create table ra_items (id int primary key, name varchar(20))',
            "insert into ra_items values (1, 'a')",
            "insert into ra_items (id, name) values (1, 'b') as new on duplicate key update name = new.name",
            'select name from ra_items',
        );

        $this->assertSame('b', $rows[0]['name']);
    }

    /**
     * TRUNCATE is refused on a table referenced by a foreign key, even with checks disabled.
     * Driver workaround: truncate() falls back to DELETE.
     */
    public function testTruncateReferencedTableWithForeignKeyChecksDisabled(): void
    {
        $this->runSql(
            'create table tr_parents (id int primary key)',
            'create table tr_children (id int primary key, parent_id int)',
            'alter table tr_children add constraint tr_children_parent_foreign foreign key (parent_id) references tr_parents (id)',
            'set foreign_key_checks = 0',
            'truncate table tr_parents',
        );

        $this->addToAssertionCount(1);
    }

    /**
     * Several tables in one DROP TABLE statement.
     * Fixed in 4.2.4 (broken in 3.0.9). Driver workaround: db:wipe drops one table at a time.
     */
    public function testDropSeveralTablesInOneStatement(): void
    {
        $this->runSql('create table dm_a (id int primary key)', 'create table dm_b (id int primary key)', 'drop table dm_a, dm_b');

        $this->addToAssertionCount(1);
    }

    /**
     * JSON columns with an expression default (MySQL 8.0.13+).
     * Driver workaround: throws, or drops the default with `ignore_json_defaults`.
     */
    public function testJsonColumnDefault(): void
    {
        $this->runSql('create table jd_docs (id int primary key, data json not null default (json_object()))');

        $this->addToAssertionCount(1);
    }

    /**
     * Functional (expression) indexes, MySQL 8.0.13+. Draft RFC #28053.
     * Driver workaround: none; the docs recommend an extra indexed column.
     */
    public function testFunctionalIndex(): void
    {
        $this->runSql(
            'create table fi_users (id int primary key, name varchar(50))',
            'create index fi_users_lower_name on fi_users ((lower(name)))',
        );

        $this->addToAssertionCount(1);
    }

    /**
     * Full-text query expansion.
     * Driver workaround: whereFullText(..., ['expanded' => true]) throws.
     */
    public function testFullTextQueryExpansion(): void
    {
        $this->runSql(
            'create table qe_docs (id int primary key, body text)',
            "insert into qe_docs values (1, 'matrixone database')",
            'alter table qe_docs add fulltext qe_docs_body_fulltext(body)',
            "select id from qe_docs where match(body) against('database' with query expansion)",
        );

        $this->addToAssertionCount(1);
    }

    /**
     * Shared row locks.
     * Fixed in 4.2.4 (broken in 3.0.9). Driver workaround: sharedLock() compiles to FOR UPDATE.
     */
    public function testSharedLocks(): void
    {
        $this->runSql('create table sl_items (id int primary key)', 'select * from sl_items for share');

        $this->addToAssertionCount(1);
    }

    /**
     * RAND() with a seed.
     * Driver workaround: the seed of inRandomOrder() is dropped.
     */
    public function testSeededRand(): void
    {
        $this->runSql('select rand(42) as r');

        $this->addToAssertionCount(1);
    }

    /**
     * Boolean expressions are returned as the strings "true"/"false".
     * Fixed in 4.2.4 (broken in 3.0.9). Driver workaround: exists(), hasTable() and index flags use if(expr, 1, 0).
     */
    public function testBooleanExpressionsAreIntegers(): void
    {
        $this->assertEquals([['t' => 1, 'f' => 0]], $this->runSql('select 1 = 1 as t, 1 = 2 as f'));
    }
}
