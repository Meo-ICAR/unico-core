<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Models\Client;
use Unico\Core\Models\Pratica;
use Unico\Core\Models\PraticaClient;
use Unico\Core\Pdf\PdfModuleClientScope;
use Unico\Core\Pdf\PracticeModuleData;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\PdfModule;
use Unico\Core\Models\PdfModuleField;
use Unico\Core\Pdf\PrintReadiness;
use Unico\Core\Tests\Support\CoreDatabase;

class ModulePrinterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::boot();
    }

    public function test_un_modulo_retail_non_vale_per_una_societa(): void
    {
        $module = new PdfModule([
            'tipi_prodotto' => ['Prestito'],
            'client_scope' => PdfModuleClientScope::PersonaFisica,
        ]);

        $this->assertTrue($module->appliesTo('Prestito', true));
        $this->assertFalse($module->appliesTo('Prestito', false));
        $this->assertFalse($module->appliesTo('Mutuo', true));
    }

    public function test_i_dati_di_stampa_escono_dalla_pratica_e_dal_cliente(): void
    {
        $client = new Client([
            'name' => 'Rossi',
            'first_name' => 'Mario',
            'tax_code' => 'RSSMRA80A01H501U',
            'is_person' => true,
            'email' => 'mario@example.com',
        ]);
        $client->setRelation('employer', null);

        $link = new PraticaClient;
        $link->setRelation('client', $client);

        $pratica = new Pratica([
            'codice_pratica' => 'P-1',
            'amount' => 12000,
            'nrate' => 36,
            'cognome_cliente' => 'Rossi',
            'nome_cliente' => 'Mario',
        ]);
        $pratica->setRelation('praticaClients', new \Illuminate\Database\Eloquent\Collection([$link]));
        $pratica->setRelation('fornitori', null);

        $data = (new PracticeModuleData)->for($pratica);

        $this->assertSame('P-1', $data->get('pratica.codice_pratica'));
        $this->assertEquals(12000, $data->get('pratica.amount'));
        $this->assertSame('Rossi', $data->get('client.name'));
        $this->assertSame('Rossi Mario', $data->get('client.nominativo'));
        $this->assertSame('mario@example.com', $data->get('client.email'));
    }

    private function documentFor(bool $typeSigned, bool $signed, Pratica $pratica, array $fields): Document
    {
        $module = new PdfModule(['is_active' => true]);
        $module->setRelation('pdfModuleFields', collect($fields));

        $type = new DocumentType(['is_signed' => $typeSigned]);
        $type->setRelation('pdfModule', $module);

        $document = new Document(['is_signed' => $signed]);
        $document->setRelation('documentType', $type);
        $document->setRelation('documentable', $pratica);

        return $document;
    }

    private function pratica(array $attributes): Pratica
    {
        $pratica = new Pratica($attributes);
        $pratica->setRelation('praticaClients', new \Illuminate\Database\Eloquent\Collection);
        $pratica->setRelation('fornitori', null);

        return $pratica;
    }

    public function test_solo_i_campi_obbligatori_bloccano_la_stampa(): void
    {
        $fields = [
            new PdfModuleField(['source_key' => 'pratica.amount', 'is_required' => true]),
            new PdfModuleField(['source_key' => 'client.email', 'is_required' => false]),
            new PdfModuleField(['source_key' => 'pratica.nrate', 'is_required' => true]),
        ];
        $readiness = new PrintReadiness(new PracticeModuleData);

        $document = $this->documentFor(true, false, $this->pratica(['amount' => 5000]), $fields);
        $this->assertSame(['pratica.nrate'], $readiness->missing($document));

        $document = $this->documentFor(true, false, $this->pratica(['amount' => 5000, 'nrate' => 24]), $fields);
        $this->assertSame([], $readiness->missing($document));
    }

    public function test_i_documenti_firmati_o_senza_firma_non_si_controllano(): void
    {
        $fields = [new PdfModuleField(['source_key' => 'pratica.amount', 'is_required' => true])];
        $readiness = new PrintReadiness(new PracticeModuleData);

        $this->assertNull($readiness->missing($this->documentFor(true, true, $this->pratica([]), $fields)));
        $this->assertNull($readiness->missing($this->documentFor(false, false, $this->pratica([]), $fields)));
    }
}
