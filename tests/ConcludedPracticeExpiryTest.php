<?php

namespace Unico\Core\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\TestCase;
use Unico\Core\Documents\ConcludedPracticeExpiry;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Models\Client;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\Pratica;
use Unico\Core\Models\PraticaClient;
use Unico\Core\Models\PraticaStato;
use Unico\Core\MorphMap;
use Unico\Core\Tests\Support\CoreDatabase;

class ConcludedPracticeExpiryTest extends TestCase
{
    private PraticaStato $inLavorazione;

    private PraticaStato $conclusa;

    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('documents', 'document_types', 'pratica_clients', 'pratiches', 'pratica_stati', 'clients');
        Relation::morphMap(MorphMap::all(), merge: true);

        $this->inLavorazione = PraticaStato::create(['codice' => 'LAV', 'tipo_finanziamento' => 'Test', 'name' => 'In lavorazione', 'ordine' => 0, 'colore' => 'gray', 'is_working' => true]);
        $this->conclusa = PraticaStato::create(['codice' => 'FIN', 'tipo_finanziamento' => 'Test', 'name' => 'Conclusa', 'ordine' => 0, 'colore' => 'gray', 'is_working' => false]);
    }

    private function pratica(string $codice, ?PraticaStato $stato = null, ?Client $cliente = null): Pratica
    {
        $pratica = Pratica::create(['codice_pratica' => $codice, 'pratica_stato_id' => ($stato ?? $this->inLavorazione)->id, 'upload_at' => now()]);

        if ($cliente !== null) {
            PraticaClient::create(['pratica_id' => $pratica->id, 'client_id' => $cliente->id, 'role' => 'richiedente', 'name' => $cliente->name]);
        }

        return $pratica;
    }

    private function documento(string $tipo, int $id): Document
    {
        return Document::create([
            'documentable_type' => $tipo, 'documentable_id' => $id, 'document_type_id' => DocumentType::create(['name' => 'Tipo '.uniqid(), 'code' => 'T'.uniqid()])->id,
            'name' => 'Doc '.uniqid(), 'is_monitored' => true, 'expires_at' => '2027-01-31',
        ]);
    }

    private function scadenza(Document $documento): ?string
    {
        return Document::query()->whereKey($documento->id)->value('expires_at');
    }

    public function test_when_the_practice_is_concluded_its_documents_and_its_clients_lose_the_expiry(): void
    {
        $cliente = Client::create(['name' => 'Rossi']);
        $altroCliente = Client::create(['name' => 'Bianchi']);
        $pratica = $this->pratica('P-1', null, $cliente);
        $altraPratica = $this->pratica('P-2', null, $altroCliente);

        $dellaPratica = $this->documento('pratica', $pratica->id);
        $delCliente = $this->documento('client', $cliente->id);
        $dellAltraPratica = $this->documento('pratica', $altraPratica->id);
        $delAltroCliente = $this->documento('client', $altroCliente->id);

        $pratica->update(['pratica_stato_id' => $this->conclusa->id]);

        $this->assertNull($this->scadenza($dellaPratica));
        $this->assertNull($this->scadenza($delCliente));
        $this->assertNotNull($this->scadenza($dellAltraPratica));
        $this->assertNotNull($this->scadenza($delAltroCliente));
    }

    public function test_a_change_between_working_states_or_a_save_without_a_state_change_keeps_the_expiry(): void
    {
        $cliente = Client::create(['name' => 'Rossi']);
        $pratica = $this->pratica('P-1', null, $cliente);
        $documento = $this->documento('client', $cliente->id);
        $altroLavorazione = PraticaStato::create(['codice' => 'LAV2', 'tipo_finanziamento' => 'Test', 'name' => 'Altro', 'ordine' => 0, 'colore' => 'gray', 'is_working' => true]);

        $pratica->update(['pratica_stato_id' => $altroLavorazione->id]);
        $pratica->update(['cognome_cliente' => 'Rossi']);

        $this->assertSame('2027-01-31', substr((string) $this->scadenza($documento), 0, 10));
    }

    public function test_a_practice_without_a_state_counts_as_in_lavorazione(): void
    {
        $pratica = Pratica::create(['codice_pratica' => 'P-0', 'upload_at' => now()]);
        $documento = $this->documento('pratica', $pratica->id);

        $this->assertFalse((new ConcludedPracticeExpiry)->isConcluded($pratica));
        $this->assertNotNull($this->scadenza($documento));
    }

    public function test_the_batch_applies_the_rule_to_practices_already_concluded(): void
    {
        $cliente = Client::create(['name' => 'Rossi']);
        $conclusa = $this->pratica('P-1', null, $cliente);
        $aperta = $this->pratica('P-2', null, Client::create(['name' => 'Bianchi']));
        // Stato cambiato senza passare dal modello (come una correzione diretta sul database).
        Pratica::query()->whereKey($conclusa->id)->toBase()->update(['pratica_stato_id' => $this->conclusa->id]);

        $dellaConclusa = $this->documento('pratica', $conclusa->id);
        $delCliente = $this->documento('client', $cliente->id);
        $dellAperta = $this->documento('pratica', $aperta->id);

        $fatti = (new ConcludedPracticeExpiry)->clearAll();

        $this->assertSame(2, $fatti['scadenze']);
        $this->assertNull($this->scadenza($dellaConclusa));
        $this->assertNull($this->scadenza($delCliente));
        $this->assertNotNull($this->scadenza($dellAperta));
        $this->assertSame(['scadenze' => 0, 'richiesti' => 0], (new ConcludedPracticeExpiry)->clearAll(), 'Ripetibile: la seconda volta non c\'è più niente da fare.');
    }

    private function richiesto(string $tipo, int $id): Document
    {
        return Document::create([
            'documentable_type' => $tipo, 'documentable_id' => $id, 'document_type_id' => DocumentType::create(['name' => 'Tipo '.uniqid(), 'code' => 'T'.uniqid()])->id,
            'name' => 'Richiesto '.uniqid(), 'status' => DocumentStatus::PENDING,
        ]);
    }

    private function stato(Document $documento): string
    {
        return Document::query()->whereKey($documento->id)->firstOrFail()->status->value;
    }

    public function test_requested_documents_become_na_but_only_for_clients_without_other_open_practices(): void
    {
        $solo = Client::create(['name' => 'Solo']);
        $conAltra = Client::create(['name' => 'ConAltra']);
        $pratica = $this->pratica('P-1', null, $solo);
        PraticaClient::create(['pratica_id' => $pratica->id, 'client_id' => $conAltra->id, 'role' => 'coobbligato', 'name' => 'ConAltra']);
        $this->pratica('P-ALTRA', null, $conAltra);

        $dellaPratica = $this->richiesto('pratica', $pratica->id);
        $delSolo = $this->richiesto('client', $solo->id);
        $delConAltra = $this->richiesto('client', $conAltra->id);
        $approvato = Document::create(['documentable_type' => 'client', 'documentable_id' => $solo->id, 'document_type_id' => DocumentType::create(['name' => 'Tipo '.uniqid(), 'code' => 'T'.uniqid()])->id, 'name' => 'OK', 'status' => DocumentStatus::APPROVED]);

        $pratica->update(['pratica_stato_id' => $this->conclusa->id]);

        $this->assertSame('na', $this->stato($dellaPratica));
        $this->assertSame('na', $this->stato($delSolo));
        $this->assertSame('richiesto', $this->stato($delConAltra), 'Il cliente ha un\'altra pratica in lavorazione: il documento serve ancora.');
        $this->assertSame('approvato', $this->stato($approvato), 'Solo i documenti «richiesti» cambiano.');
    }

    public function test_the_batch_marks_requested_documents_of_concluded_practices_as_na(): void
    {
        $cliente = Client::create(['name' => 'Rossi']);
        $pratica = $this->pratica('P-1', null, $cliente);
        Pratica::query()->whereKey($pratica->id)->toBase()->update(['pratica_stato_id' => $this->conclusa->id]);
        $richiesto = $this->richiesto('client', $cliente->id);

        $fatti = (new ConcludedPracticeExpiry)->clearAll();

        $this->assertSame(1, $fatti['richiesti']);
        $this->assertSame('na', $this->stato($richiesto));
    }
}
