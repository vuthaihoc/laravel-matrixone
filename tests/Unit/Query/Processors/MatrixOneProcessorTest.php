<?php

namespace MatrixOne\Tests\Unit\Query\Processors;

use MatrixOne\Query\Processors\MatrixOneProcessor;
use MatrixOne\Tests\Unit\TestCase;

class MatrixOneProcessorTest extends TestCase
{
    public function testForeignKeysAreReadFromShowCreateTable(): void
    {
        // Verbatim SHOW CREATE TABLE output of MatrixOne 4.2.6.
        $sql = <<<'SQL'
            CREATE TABLE `child` (
              `id` int NOT NULL,
              `a` int DEFAULT NULL,
              `bx` int DEFAULT NULL,
              `e` int DEFAULT NULL,
              `s` int DEFAULT NULL,
              PRIMARY KEY (`id`),
              CONSTRAINT `multi fk` FOREIGN KEY (`a`,`bx`) REFERENCES `we.ird` (`a`,`b``x`) ON DELETE SET NULL ON UPDATE CASCADE,
              CONSTRAINT `01a166e7-9962-7e8b-b1b6-acca6935e304` FOREIGN KEY (`e`) REFERENCES `fk_probe2`.`ext` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `self_fk` FOREIGN KEY (`s`) REFERENCES `child` (`id`) ON DELETE NO ACTION ON UPDATE SET NULL
            )
            SQL;

        $this->assertSame([
            [
                'name' => 'multi fk',
                'columns' => ['a', 'bx'],
                'foreign_schema' => 'fk_probe',
                'foreign_table' => 'we.ird',
                'foreign_columns' => ['a', 'b`x'],
                'on_update' => 'cascade',
                'on_delete' => 'set null',
            ],
            [
                'name' => '01a166e7-9962-7e8b-b1b6-acca6935e304',
                'columns' => ['e'],
                'foreign_schema' => 'fk_probe2',
                'foreign_table' => 'ext',
                'foreign_columns' => ['id'],
                'on_update' => 'restrict',
                'on_delete' => 'restrict',
            ],
            [
                'name' => 'self_fk',
                'columns' => ['s'],
                'foreign_schema' => 'fk_probe',
                'foreign_table' => 'child',
                'foreign_columns' => ['id'],
                'on_update' => 'set null',
                'on_delete' => 'no action',
            ],
        ], (new MatrixOneProcessor)->processCreateTableForeignKeys($sql, 'fk_probe'));
    }

    public function testMissingActionsDefaultToRestrict(): void
    {
        $keys = (new MatrixOneProcessor)->processCreateTableForeignKeys(
            "CREATE TABLE `t` (\n  `p` int,\n  CONSTRAINT `fk` FOREIGN KEY (`p`) REFERENCES `p` (`id`)\n)",
            'app'
        );

        $this->assertSame('restrict', $keys[0]['on_update']);
        $this->assertSame('restrict', $keys[0]['on_delete']);
    }

    public function testColumnTextIsNotMistakenForAForeignKey(): void
    {
        $sql = "CREATE TABLE `t` (\n  `note` varchar(80) DEFAULT 'CONSTRAINT `x` FOREIGN KEY (`a`) REFERENCES `b` (`c`)',\n  PRIMARY KEY (`note`)\n)";

        $this->assertSame([], (new MatrixOneProcessor)->processCreateTableForeignKeys($sql, 'app'));
    }
}
