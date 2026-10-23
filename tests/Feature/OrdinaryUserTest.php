<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOException;

/**
 * An application user that owns one database through a role, as created by
 *
 *     create role r; grant all on database d to r; grant all on table d.* to r;
 *     create user u identified by '...' default role r;
 *
 * MatrixOne denies such a user `use d` and the system catalogs.
 */
class OrdinaryUserTest extends TestCase
{
    private const DATABASE = 'laravel_matrixone_user_test';

    private const ROLE = 'laravel_matrixone_user_rw';

    private const USER = 'laravel_matrixone_user';

    private const PASSWORD = 'secret-1';

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->admin();
        $this->dropUser($admin);

        try {
            $admin->exec('create database `'.self::DATABASE.'`');
            $admin->exec('create role '.self::ROLE);
            $admin->exec('grant all on database `'.self::DATABASE.'` to '.self::ROLE);
            $admin->exec('grant all on table `'.self::DATABASE.'`.* to '.self::ROLE);
            $admin->exec('create user '.self::USER." identified by '".self::PASSWORD."' default role ".self::ROLE);
        } catch (PDOException $e) {
            $this->markTestSkipped('The test user needs an account admin to create roles and users: '.$e->getMessage());
        }

        config()->set('database.connections.matrixone_user', array_merge(config('database.connections.matrixone'), [
            'database' => self::DATABASE,
            'username' => self::USER,
            'password' => self::PASSWORD,
        ]));
    }

    protected function tearDown(): void
    {
        DB::purge('matrixone_user');

        $this->dropUser($this->admin());

        parent::tearDown();
    }

    public function testAnOrdinaryUserConnectsWithoutSelectingTheDatabaseWithUse(): void
    {
        $this->assertSame(self::DATABASE, DB::connection('matrixone_user')->scalar('select database()'));
    }

    public function testUseIsDeniedToAnOrdinaryUser(): void
    {
        config()->set('database.connections.matrixone_user.use_db_after_connecting', true);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('do not have privilege');

        DB::connection('matrixone_user')->scalar('select 1');
    }

    public function testAnOrdinaryUserReadsForeignKeys(): void
    {
        $schema = Schema::connection('matrixone_user');

        $schema->create('ou_users', function (Blueprint $table) {
            $table->id();
        });
        $schema->create('ou_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('ou_users')->cascadeOnDelete();
        });

        $this->assertTrue($schema->hasTable('ou_posts'));
        $this->assertSame([[
            'name' => 'ou_posts_user_id_foreign',
            'columns' => ['user_id'],
            'foreign_schema' => self::DATABASE,
            'foreign_table' => 'ou_users',
            'foreign_columns' => ['id'],
            'on_update' => 'restrict',
            'on_delete' => 'cascade',
        ]], $schema->getForeignKeys('ou_posts'));
        $this->assertSame([], $schema->getForeignKeys('ou_users'));

        // The catalogs a MySQL-style lookup would use are off limits.
        foreach (['mo_catalog.mo_foreign_keys', 'information_schema.key_column_usage'] as $catalog) {
            try {
                DB::connection('matrixone_user')->select("select * from {$catalog} limit 1");
                $this->fail("{$catalog} is readable by an ordinary user.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('do not have privilege', $e->getMessage());
            }
        }
    }

    private function admin(): PDO
    {
        $config = config('database.connections.matrixone');

        return new PDO(
            sprintf('mysql:host=%s;port=%s', $config['host'], $config['port']),
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function dropUser(PDO $admin): void
    {
        $admin->exec('drop user if exists '.self::USER);
        $admin->exec('drop role if exists '.self::ROLE);
        $admin->exec('drop database if exists `'.self::DATABASE.'`');
    }
}
