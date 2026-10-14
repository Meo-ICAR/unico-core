<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Tests\Support\CoreDatabase;

/** Ogni tabella e ogni colonna del core deve avere un commento. */
class SchemaCommentsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    public function test_every_table_has_a_comment(): void
    {
        $rows = CoreDatabase::$capsule->getConnection('core')->select(
            "select table_name as name from information_schema.tables
             where table_schema = database() and (table_comment is null or table_comment = '')"
        );

        $this->assertSame([], array_map(fn ($r) => $r->name, $rows));
    }

    public function test_every_column_has_a_comment(): void
    {
        $rows = CoreDatabase::$capsule->getConnection('core')->select(
            "select concat(table_name, '.', column_name) as name from information_schema.columns
             where table_schema = database() and (column_comment is null or column_comment = '')
             order by table_name, ordinal_position"
        );

        $this->assertSame([], array_map(fn ($r) => $r->name, $rows));
    }
}
