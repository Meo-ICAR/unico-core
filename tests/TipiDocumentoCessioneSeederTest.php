<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Models\DocumentType;
use Unico\Core\Seeders\TipiDocumentoCessioneSeeder;
use Unico\Core\Tests\Support\CoreDatabase;

class TipiDocumentoCessioneSeederTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('document_types');
    }

    public function test_crea_i_tipi_nuovi_una_sola_volta_senza_toccare_quelli_esistenti(): void
    {
        DocumentType::create(['name' => 'Questionario Compass (QUIND)', 'description' => 'scritta a mano', 'client_scope' => 'persona_giuridica']);

        (new TipiDocumentoCessioneSeeder)->run();
        (new TipiDocumentoCessioneSeeder)->run();

        $this->assertSame(count(TipiDocumentoCessioneSeeder::NUOVI), DocumentType::query()->whereIn('name', array_keys(TipiDocumentoCessioneSeeder::NUOVI))->count());
        $esistente = DocumentType::query()->where('name', 'Questionario Compass (QUIND)')->firstOrFail();
        $this->assertSame(['scritta a mano', 'persona_giuridica'], [$esistente->description, $esistente->client_scope]);

        $nuovo = DocumentType::query()->where('name', 'Autocertificazione TFR disponibile')->firstOrFail();
        $this->assertSame(['autocertificazione-tfr-disponibile', 'persona_fisica', 'cliente', true, 'client'], [$nuovo->slug, $nuovo->client_scope, $nuovo->source, (bool) $nuovo->is_signed, $nuovo->signer_model]);
        $this->assertSame('MODULISTICA_TFR', DocumentType::query()->where('name', 'Modulistica TFR / fondo pensione')->value('codegroup'));
        $this->assertFalse((bool) DocumentType::query()->where('name', 'Verifica assicurabilità (esito)')->value('is_signed'));
    }

    public function test_assegna_i_profili_di_lettura_solo_dove_sono_vuoti(): void
    {
        DocumentType::create(['name' => 'Busta paga 1']);
        DocumentType::create(['name' => 'Certificazione Unica (CU)']);
        DocumentType::create(['name' => 'Certificato di stipendio e quota cedibile', 'ai_kind' => 'reddito']);
        DocumentType::create(['name' => 'Carta d\'Identità']);

        (new TipiDocumentoCessioneSeeder)->run();

        $this->assertSame('busta_paga', DocumentType::query()->where('name', 'Busta paga 1')->value('ai_kind'));
        $this->assertSame('cu', DocumentType::query()->where('name', 'Certificazione Unica (CU)')->value('ai_kind'));
        $this->assertSame('reddito', DocumentType::query()->where('name', 'Certificato di stipendio e quota cedibile')->value('ai_kind'), 'Un profilo scelto a mano non si cambia.');
        $this->assertNull(DocumentType::query()->where('name', 'Carta d\'Identità')->value('ai_kind'), 'Gli altri tipi non vengono toccati.');
    }
}
