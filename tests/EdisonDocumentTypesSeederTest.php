<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Models\DocumentType;
use Unico\Core\Seeders\EdisonDocumentTypesSeeder;
use Unico\Core\Tests\Support\CoreDatabase;

class EdisonDocumentTypesSeederTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('document_types');
    }

    public function test_crea_i_tipi_edison_una_sola_volta(): void
    {
        (new EdisonDocumentTypesSeeder)->run();
        (new EdisonDocumentTypesSeeder)->run();

        foreach (EdisonDocumentTypesSeeder::NEW_TYPES as $name => $attributes) {
            $this->assertSame(1, DocumentType::where('name', $name)->count(), $name);
        }
        $bolletta = DocumentType::where('name', 'Bolletta luce/gas')->first();
        $this->assertSame('bolletta', $bolletta->ai_kind);
        $this->assertFalse((bool) $bolletta->is_signed);
        $this->assertTrue((bool) DocumentType::where('name', 'Contratto di fornitura Edison')->first()->is_signed);
    }

    public function test_imposta_ai_kind_su_visura_ed_estratto_conto_solo_se_vuoto(): void
    {
        DocumentType::create(['name' => 'Visura camerale', 'code' => 'VISURA_CAMERALE']);
        DocumentType::create(['name' => 'Estratto Conto Bancario', 'code' => 'ESTRATTO_CONTO', 'ai_kind' => 'altro']);

        (new EdisonDocumentTypesSeeder)->run();

        $this->assertSame('visura', DocumentType::where('code', 'VISURA_CAMERALE')->value('ai_kind'));
        $this->assertSame('altro', DocumentType::where('code', 'ESTRATTO_CONTO')->value('ai_kind'));
    }
}
