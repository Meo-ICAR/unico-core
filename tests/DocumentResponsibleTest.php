<?php

namespace Unico\Core\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\TestCase;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Models\Client;
use Unico\Core\Models\Cliente;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\Employee;
use Unico\Core\Models\Fornitore;
use Unico\Core\Models\Pratica;
use Unico\Core\Models\TipoProdotto;
use Unico\Core\Tests\Support\CoreDatabase;

class DocumentResponsibleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('documents', 'document_types', 'pratiches', 'clients', 'clientis', 'employees', 'fornitoris', 'tipoprodotto');
        Relation::morphMap(\Unico\Core\MorphMap::all(), merge: true);
    }

    /** @return array{0: Document, 1: array<string, \Illuminate\Database\Eloquent\Model>} */
    private function scenario(?string $issuer = null, bool $producerAllows = false, ?bool $praticaAllows = null, string $product = 'Cessione', DocumentStatus $status = DocumentStatus::PENDING): array
    {
        $n = uniqid();
        $produttore = Fornitore::create(['name' => 'Produttore '.$n, 'allows_direct_requests' => $producerAllows]);
        $istruttore = Employee::create(['name' => 'Istruttore '.$n]);
        $banca = Cliente::create(['name' => 'Banca '.$n]);
        $datore = Client::create(['name' => 'Ente '.$n, 'is_person' => false]);
        $cliente = Client::create(['name' => 'Rossi '.$n, 'tax_code' => 'RSS'.strtoupper(substr($n, -8)), 'is_person' => true, 'employer_id' => $datore->id]);
        $prodotto = TipoProdotto::firstOrCreate(['name' => $product]);
        Pratica::create(['codice_pratica' => 'P-'.$n, 'codice_fiscale' => $cliente->tax_code, 'fornitori_id' => $produttore->id, 'employee_id' => $istruttore->id, 'clienti_id' => $banca->id, 'tipoprodotto_id' => $prodotto->id, 'allows_direct_requests' => $praticaAllows, 'upload_at' => now()]);
        $tipo = DocumentType::create(['name' => 'Doc '.$n, 'code' => 'D'.$n, 'issuer_model' => $issuer]);
        $documento = Document::create(['documentable_type' => 'client', 'documentable_id' => (string) $cliente->id, 'document_type_id' => $tipo->id, 'name' => 'Doc', 'status' => $status]);

        return [$documento->fresh(), compact('produttore', 'istruttore', 'banca', 'datore', 'cliente')];
    }

    private function responsible(Document $document): ?string
    {
        $document = $document->fresh();

        return $document->responsible_type === null ? null : $document->responsible_type.':'.$document->responsible_id;
    }

    public function test_da_compilare_stampare_o_inviare_otp_e_dell_istruttore_della_pratica(): void
    {
        [$documento, $m] = $this->scenario();

        foreach ([DocumentStatus::MISSING_DATA, DocumentStatus::PRINT, DocumentStatus::TO_SEND_OTP] as $status) {
            $documento->update(['status' => $status]);
            $this->assertSame('employee:'.$m['istruttore']->id, $this->responsible($documento), $status->value);
        }
    }

    public function test_un_documento_del_cliente_si_chiede_al_produttore_salvo_richieste_dirette(): void
    {
        [$documento, $m] = $this->scenario();
        $this->assertSame('fornitore:'.$m['produttore']->id, $this->responsible($documento), 'Senza flag: override con il produttore.');

        [$documento, $m] = $this->scenario(producerAllows: true);
        $this->assertSame('client:'.$m['cliente']->id, $this->responsible($documento));

        [$documento, $m] = $this->scenario(producerAllows: true, praticaAllows: false);
        $this->assertSame('fornitore:'.$m['produttore']->id, $this->responsible($documento), 'Il flag della pratica prevale.');
    }

    public function test_il_benestare_e_del_datore_di_lavoro_e_il_contratto_della_banca(): void
    {
        [$benestare, $m] = $this->scenario(issuer: 'employer');
        $this->assertSame('client:'.$m['datore']->id, $this->responsible($benestare));

        [$mutuo, $m] = $this->scenario(issuer: 'employer', product: 'Mutuo');
        $this->assertSame('client:'.$m['cliente']->id, $this->responsible($mutuo), 'Il datore di lavoro entra solo in cessione e delega.');

        [$contratto, $m] = $this->scenario(issuer: 'institute');
        $this->assertSame('cliente:'.$m['banca']->id, $this->responsible($contratto));
    }

    public function test_dopo_l_invio_firma_il_richiedente_e_a_esito_nessuno(): void
    {
        [$documento, $m] = $this->scenario();

        $documento->update(['status' => DocumentStatus::SENT_OTP]);
        $this->assertSame('client:'.$m['cliente']->id, $this->responsible($documento));

        $documento->update(['status' => DocumentStatus::APPROVED]);
        $this->assertNull($this->responsible($documento));
    }

    public function test_la_scelta_a_mano_resta_fino_al_cambio_di_fase(): void
    {
        [$documento, $m] = $this->scenario();

        $documento->update(['status' => DocumentStatus::PRINT, 'responsible_type' => 'client', 'responsible_id' => (string) $m['datore']->id]);
        $this->assertSame('client:'.$m['datore']->id, $this->responsible($documento));

        $documento->fresh()->update(['status' => DocumentStatus::TO_SEND_OTP]);
        $this->assertSame('employee:'.$m['istruttore']->id, $this->responsible($documento));
    }
}
