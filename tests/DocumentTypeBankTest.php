<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Models\DocumentType;
use Unico\Core\Tests\Support\CoreDatabase;

class DocumentTypeBankTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('document_types');
    }

    public function test_un_documento_di_una_banca_vale_solo_per_le_pratiche_di_quella_banca(): void
    {
        $all = DocumentType::create(['name' => 'Fascicolo', 'code' => 'F']);
        $compass = DocumentType::create(['name' => 'Informativa Compass', 'code' => 'IC', 'clienti_id' => 7]);
        $other = DocumentType::create(['name' => 'Allegato B', 'code' => 'AB', 'clienti_id' => 9]);

        $this->assertEqualsCanonicalizing([$all->id], DocumentType::query()->forBank(null)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$all->id, $compass->id], DocumentType::query()->forBank(7)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$all->id, $other->id], DocumentType::query()->forBank('9')->pluck('id')->all());

        $this->assertTrue($all->appliesToBank(null));
        $this->assertFalse($compass->appliesToBank(null));
        $this->assertTrue($compass->appliesToBank(7));
        $this->assertFalse($compass->appliesToBank(9));
    }
}
