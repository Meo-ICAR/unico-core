<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Documents\PracticeClientPopulator;
use Unico\Core\Models\Client;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentAnalysis;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\Pratica;
use Unico\Core\Models\PraticaClient;
use Unico\Core\Pdf\PracticeModuleData;
use Unico\Core\Tests\Support\CoreDatabase;

class PracticeClientPopulatorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('document_analyses', 'documents', 'document_types', 'pratica_clients', 'clients', 'pratiches');
    }

    private function document(string $kind, array $fields, Pratica $pratica): Document
    {
        $type = DocumentType::create(['name' => 'Tipo '.$kind.uniqid(), 'code' => 'T'.uniqid(), 'ai_kind' => $kind]);
        $document = Document::create(['documentable_type' => 'pratica', 'documentable_id' => (string) $pratica->getKey(), 'document_type_id' => $type->id, 'name' => 'doc']);
        DocumentAnalysis::create(['document_id' => $document->id, 'kind' => 'extraction', 'outcome' => 'passed', 'result' => ['fields' => $fields], 'operator_type' => 'ai', 'operator_label' => 'ai.test']);

        return $document->fresh('documentType');
    }

    private function identity(array $overrides = []): array
    {
        return $overrides + ['surname' => 'Rossi', 'name' => 'Mario', 'fiscal_code' => 'rssmra80a01h501u', 'birth_date' => '01/01/1980', 'birth_place' => 'Roma',
            'document_number' => 'AX123', 'expiry_date' => '01/01/2040', 'issuer' => 'Comune di Roma', 'issue_date' => '10/01/2030'];
    }

    public function test_dal_documento_d_identita_nasce_l_anagrafica_collegata_alla_pratica(): void
    {
        $pratica = Pratica::create(['codice_pratica' => 'P-1', 'upload_at' => now()]);
        $document = $this->document('identita', $this->identity(), $pratica);

        $client = (new PracticeClientPopulator(new PracticeModuleData))->apply($pratica, $document);

        $this->assertNotNull($client);
        $this->assertSame('RSSMRA80A01H501U', $client->tax_code);
        $this->assertSame('Rossi', $client->name);
        $this->assertSame('Mario', $client->first_name);
        $this->assertSame('1980-01-01', $client->birth_date->toDateString());
        $this->assertTrue($client->is_person);
        $this->assertSame('richiedente', PraticaClient::query()->where('pratica_id', $pratica->id)->where('client_id', $client->id)->value('role'));

        $pratica->refresh();
        $this->assertSame('Rossi', $pratica->cognome_cliente);
        $this->assertSame('RSSMRA80A01H501U', $pratica->codice_fiscale);

        $document->refresh();
        $this->assertSame('AX123', $document->docnumber);
        $this->assertSame('Comune di Roma', $document->emitted_by);
        $this->assertSame('2030-01-10', $document->emitted_at->toDateString());
        $this->assertSame('2040-01-01', $document->expires_at->toDateString());
    }

    public function test_non_sovrascrive_i_dati_gia_presenti_e_non_duplica_il_cliente(): void
    {
        $pratica = Pratica::create(['codice_pratica' => 'P-2', 'upload_at' => now()]);
        $populator = new PracticeClientPopulator(new PracticeModuleData);

        $first = $populator->apply($pratica, $this->document('identita', $this->identity(), $pratica));
        $first->update(['name' => 'Rossi Verdi', 'birth_place' => 'Milano']);

        $second = $populator->apply($pratica->fresh(), $this->document('codice_fiscale', $this->identity(['surname' => 'ROSSI', 'birth_place' => 'Roma', 'birth_date' => '02/02/1990']), $pratica));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Client::query()->count());
        $this->assertSame('Rossi Verdi', $second->fresh()->name);
        $this->assertSame('Milano', $second->fresh()->birth_place);
        $this->assertSame('1980-01-01', $second->fresh()->birth_date->toDateString());
        $this->assertSame(1, PraticaClient::query()->count());
    }

    public function test_per_una_societa_i_documenti_sono_dell_amministratore(): void
    {
        $pratica = Pratica::create(['codice_pratica' => 'P-4', 'codice_fiscale' => '12345678901', 'cognome_cliente' => 'Acme S.r.l.', 'upload_at' => now()]);

        $client = (new PracticeClientPopulator(new PracticeModuleData))->apply($pratica, $this->document('identita', $this->identity(), $pratica));

        $this->assertTrue($client->is_person);
        $this->assertSame('amministratore', PraticaClient::query()->where('pratica_id', $pratica->id)->where('client_id', $client->id)->value('role'));

        $pratica->refresh();
        $this->assertSame('Acme S.r.l.', $pratica->cognome_cliente);
        $this->assertSame('12345678901', $pratica->codice_fiscale);
    }

    public function test_i_documenti_di_altro_tipo_non_toccano_l_anagrafica(): void
    {
        $pratica = Pratica::create(['codice_pratica' => 'P-3', 'upload_at' => now()]);

        $this->assertNull((new PracticeClientPopulator(new PracticeModuleData))->apply($pratica, $this->document('reddito', $this->identity(), $pratica)));
        $this->assertSame(0, Client::query()->count());
    }
}
