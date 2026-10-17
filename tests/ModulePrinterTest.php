<?php

namespace Unico\Core\Tests;

use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use Unico\Core\Models\Address;
use Unico\Core\Models\Branch;
use Unico\Core\Models\Client;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\Fornitore;
use Unico\Core\Models\PdfModule;
use Unico\Core\Models\PdfModuleField;
use Unico\Core\Models\Pratica;
use Unico\Core\Models\PraticaClient;
use Unico\Core\Pdf\ModuleDataProvider;
use Unico\Core\Pdf\PdfModuleClientScope;
use Unico\Core\Pdf\PracticeModuleData;
use Unico\Core\Pdf\PrintReadiness;
use Unico\Core\Pdf\ResolvedModuleData;
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
        $pratica->setRelation('praticaClients', new Collection([$link]));
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

        $document = new Document(['signed_at' => $signed ? now() : null]);
        $document->setRelation('documentType', $type);
        $document->setRelation('documentable', $pratica);

        return $document;
    }

    private function pratica(array $attributes): Pratica
    {
        $pratica = new Pratica($attributes);
        $pratica->setRelation('praticaClients', new Collection);
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

    public function test_i_dati_di_mandato_kyc_e_documento_escono_dalla_pratica(): void
    {
        $type = new DocumentType(['code' => 'CARTA_IDENTITA', 'name' => 'Carta di Identità', 'codegroup' => DocumentType::CODEGROUP_IDENTITA]);
        $identity = new Document(['name' => 'Carta', 'docnumber' => 'AX123', 'emitted_by' => 'Comune di Roma', 'emitted_at' => '2024-01-10', 'expires_at' => '2099-01-10']);
        $identity->setRelation('documentType', $type);
        $scaduto = new Document(['name' => 'Vecchia', 'docnumber' => 'OLD', 'emitted_at' => '2010-01-01', 'expires_at' => '2020-01-01']);
        $scaduto->setRelation('documentType', $type);

        $client = new Client(['name' => 'Rossi', 'first_name' => 'Mario', 'profession' => 'Impiegato', 'is_pep' => false, 'consent_sic_at' => now()]);
        $client->setRelation('employer', null);
        $client->setRelation('legalRepresentative', null);
        $client->setRelation('branches', collect([new Branch(['address' => 'Via Roma', 'street_number' => '1', 'city' => 'Milano', 'zip_code' => '20100', 'province' => 'MI', 'is_main_office' => true])]));
        $client->setRelation('documents', collect([$scaduto, $identity]));

        $link = new PraticaClient(['role' => 'richiedente', 'funds_origin' => 'Risparmi']);
        $link->setRelation('client', $client);

        $pratica = $this->pratica(['codice_pratica' => 'P-9', 'mediation_fee_percent' => 2.5, 'istruttoria_costs' => 150, 'withdrawal_terms' => '14 giorni']);
        $pratica->setRelation('praticaClients', new Collection([$link]));
        $pratica->setRelation('company', null);

        $data = (new PracticeModuleData)->for($pratica);

        $this->assertSame('AX123', $data->get('document.identity.docnumber'));
        $this->assertSame('Comune di Roma', $data->get('document.identity.emitted_by'));
        $this->assertSame('Via Roma 1, 20100 Milano (MI)', $data->get('branch.indirizzo_completo'));
        $this->assertSame('Impiegato', $data->get('client.profession'));
        $this->assertTrue($data->get('client.consent_sic'));
        $this->assertFalse($data->get('client.consent_marketing'));
        $this->assertFalse($data->get('client.is_pep'));
        $this->assertTrue($data->has('client.is_pep'));
        $this->assertSame('richiedente', $data->get('pratica_client.role'));
        $this->assertSame('Risparmi', $data->get('pratica_client.funds_origin'));
        $this->assertEquals(2.5, $data->get('mediation.fee_percent'));
        $this->assertEquals(150, $data->get('mediation.istruttoria_costs'));
        $this->assertSame('14 giorni', $data->get('mediation.withdrawal_terms'));
        $this->assertFalse($data->has('mediation.fee_amount'));
    }

    public function test_la_persona_fisica_usa_gli_indirizzi_la_societa_la_sede_e_il_luogo_e_della_sede_dell_agente(): void
    {
        $residenza = new Address(['street' => 'Via Verdi', 'numero' => '7', 'city' => 'Roma', 'zip_code' => '00100', 'province' => 'RM', 'address_type_id' => 1]);
        $domicilio = new Address(['street' => 'Via Bianchi', 'numero' => '9', 'city' => 'Napoli', 'zip_code' => '80100', 'province' => 'NA', 'address_type_id' => 2]);
        $sede = new Branch(['address' => 'Via Sede', 'street_number' => '3', 'city' => 'Torino', 'zip_code' => '10100', 'province' => 'TO', 'is_main_office' => true]);

        $build = function (bool $isPerson) use ($residenza, $domicilio, $sede): PracticeModuleData|ResolvedModuleData {
            $client = new Client(['name' => 'Rossi', 'first_name' => 'Mario', 'is_person' => $isPerson, 'consent_group_at' => now()]);
            $client->setRelation('employer', null);
            $client->setRelation('legalRepresentative', null);
            $client->setRelation('documents', collect());
            $client->setRelation('addresses', collect([$domicilio, $residenza]));
            $client->setRelation('branches', collect([$sede]));

            $link = new PraticaClient(['role' => 'richiedente']);
            $link->setRelation('client', $client);

            $pratica = $this->pratica([]);
            $pratica->setRelation('praticaClients', new Collection([$link]));
            $pratica->setRelation('company', null);
            $agente = new Fornitore(['name' => 'Agente']);
            $agente->setRelation('branch', new Branch(['city' => 'Foggia']));
            $pratica->setRelation('fornitori', $agente);

            return (new PracticeModuleData)->for($pratica);
        };

        $persona = $build(true);
        $this->assertSame('Via Verdi', $persona->get('client.address.street'));
        $this->assertSame('RM', $persona->get('client.address.province'));
        $this->assertSame('Via Verdi 7, 00100 Roma (RM)', $persona->get('client.address.full'));
        $this->assertSame('Foggia', $persona->get('agent.branch_city'));
        $this->assertTrue($persona->get('client.consent_group'));
        $this->assertFalse($persona->get('client.consent_third_parties'));

        $societa = $build(false);
        $this->assertSame('Via Sede', $societa->get('client.address.street'));
        $this->assertSame('TO', $societa->get('client.address.province'));
    }

    public function test_senza_branch_dell_agente_il_luogo_resta_vuoto(): void
    {
        $pratica = $this->pratica([]);
        $pratica->setRelation('praticaClients', new Collection);
        $pratica->setRelation('company', null);
        $agente = new Fornitore(['name' => 'Agente']);
        $agente->setRelation('branch', null);
        $pratica->setRelation('fornitori', $agente);

        $this->assertFalse((new PracticeModuleData)->for($pratica)->has('agent.branch_city'));
    }

    public function test_i_dati_delle_app_si_aggiungono_ma_non_sovrascrivono_quelli_del_pacchetto(): void
    {
        $provider = new class implements ModuleDataProvider
        {
            public function values(Pratica $pratica, ?Client $client): array
            {
                return ['kyc.pep_status' => 'no', 'pratica.codice_pratica' => 'DELL-APP'];
            }
        };
        $readiness = new PrintReadiness(new PracticeModuleData([$provider]));

        $fields = [new PdfModuleField(['source_key' => 'kyc.pep_status', 'is_required' => true]), new PdfModuleField(['source_key' => 'kyc.income_band', 'is_required' => true])];
        $document = $this->documentFor(true, false, $this->pratica(['codice_pratica' => 'P-1']), $fields);

        $this->assertSame(['kyc.income_band'], $readiness->missing($document));
        $this->assertSame('P-1', (new PracticeModuleData([$provider]))->for($document->documentable)->get('pratica.codice_pratica'));
    }
}
