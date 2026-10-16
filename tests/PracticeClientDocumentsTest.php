<?php

namespace Unico\Core\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\TestCase;
use Unico\Core\Documents\PracticeTaskDocuments;
use Unico\Core\Models\Client;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\Pratica;
use Unico\Core\Models\PraticaClient;
use Unico\Core\Models\Task;
use Unico\Core\Models\TipoProdotto;
use Unico\Core\MorphMap;
use Unico\Core\Tests\Support\CoreDatabase;

class PracticeClientDocumentsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
        Relation::morphMap(MorphMap::all(), merge: true);
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('documents', 'document_requirements', 'document_types', 'tasks', 'pratica_clients', 'clients', 'pratiches', 'tipoprodotto');
    }

    public function test_i_documenti_del_task_nascono_sul_client_del_ruolo_indicato(): void
    {
        $cessione = TipoProdotto::create(['name' => 'Cessione']);
        $task = Task::create(['name' => 'Cessione · PERFEZIONARE', 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_value' => 'Cessione', 'tipoprodotto_id' => $cessione->id, 'trigger_subfield' => 'stato_pratica', 'trigger_subvalue' => 'PERFEZIONARE']);
        $contratto = DocumentType::create(['name' => 'Contratto', 'code' => 'CT']);
        $identita = DocumentType::create(['name' => 'Carta identità', 'code' => 'CI']);
        $privacy = DocumentType::create(['name' => 'Privacy garante', 'code' => 'PG']);
        DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $contratto->id]);
        DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $identita->id, 'documentable' => 'client']);
        DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $privacy->id, 'documentable' => 'client', 'client_roles' => ['garante']]);

        $pratica = Pratica::create(['codice_pratica' => 'P-C1', 'upload_at' => now(), 'tipoprodotto_id' => $cessione->id]);
        $richiedente = Client::create(['name' => 'Rossi', 'is_person' => true]);
        $garante = Client::create(['name' => 'Bianchi', 'is_person' => true]);
        PraticaClient::create(['pratica_id' => $pratica->getKey(), 'client_id' => $richiedente->id, 'role' => 'richiedente']);
        PraticaClient::create(['pratica_id' => $pratica->getKey(), 'client_id' => $garante->id, 'role' => 'garante']);

        $created = (new PracticeTaskDocuments)->attachToPractice($pratica);

        $this->assertSame(1 + 2 + 1, $created);
        $sul = fn (string $type, $id) => Document::query()->where('documentable_type', $type)->where('documentable_id', (string) $id)->pluck('document_type_id')->sort()->values()->all();
        $this->assertSame([$contratto->id], $sul('pratica', $pratica->getKey()));
        $this->assertSame([$identita->id], $sul('client', $richiedente->id));
        $this->assertSame([$identita->id, $privacy->id], $sul('client', $garante->id));
    }

    public function test_genera_documenti_crea_quelli_del_plico_sul_model_giusto_e_non_duplica(): void
    {
        $task = Task::create(['name' => 'Plico pratica', 'taskable' => 'pratica']);
        $contratto = DocumentType::create(['name' => 'Contratto', 'code' => 'CT']);
        $identita = DocumentType::create(['name' => 'Carta identità', 'code' => 'CI']);
        DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $contratto->id]);
        DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $identita->id, 'documentable' => 'client', 'client_roles' => ['richiedente']]);

        $pratica = Pratica::create(['codice_pratica' => 'P-C2', 'upload_at' => now()]);
        $richiedente = Client::create(['name' => 'Rossi', 'is_person' => true]);
        $garante = Client::create(['name' => 'Bianchi', 'is_person' => true]);
        PraticaClient::create(['pratica_id' => $pratica->getKey(), 'client_id' => $richiedente->id, 'role' => 'richiedente']);
        PraticaClient::create(['pratica_id' => $pratica->getKey(), 'client_id' => $garante->id, 'role' => 'garante']);

        $this->assertSame(['created' => 2, 'existing' => 0], $task->generateDocumentsFor($pratica));
        $this->assertSame(['created' => 0, 'existing' => 2], $task->generateDocumentsFor($pratica));
        $this->assertSame(0, Document::query()->where('documentable_type', 'client')->where('documentable_id', (string) $garante->id)->count());
        $this->assertSame(
            [$contratto->id, $identita->id],
            (new \Unico\Core\Documents\PlicoArchive)->documenti($task, $pratica)->pluck('document_type_id')->sort()->values()->all(),
        );
    }

    public function test_i_documenti_seguono_i_ruoli_indicati_e_il_tipo_persona_fisica_o_giuridica(): void
    {
        $task = Task::create(['name' => 'Mutuo', 'taskable' => 'pratica']);
        $identita = DocumentType::create(['name' => 'Carta identità', 'code' => 'CI', 'client_scope' => 'persona_fisica']);
        $visura = DocumentType::create(['name' => 'Visura', 'code' => 'VI', 'client_scope' => 'persona_giuridica']);
        DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $identita->id, 'documentable' => 'client', 'client_roles' => ['richiedente', 'coobbligato']]);
        DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $visura->id, 'documentable' => 'client']);

        $pratica = Pratica::create(['codice_pratica' => 'P-C3', 'upload_at' => now()]);
        $societa = Client::create(['name' => 'Acme Srl', 'is_person' => false]);
        $coobbligato = Client::create(['name' => 'Verdi', 'is_person' => true]);
        $garante = Client::create(['name' => 'Neri', 'is_person' => true]);
        PraticaClient::create(['pratica_id' => $pratica->getKey(), 'client_id' => $societa->id, 'role' => 'richiedente']);
        PraticaClient::create(['pratica_id' => $pratica->getKey(), 'client_id' => $coobbligato->id, 'role' => 'coobbligato']);
        PraticaClient::create(['pratica_id' => $pratica->getKey(), 'client_id' => $garante->id, 'role' => 'garante']);

        $task->generateDocumentsFor($pratica);

        $sul = fn (Client $client) => Document::query()->where('documentable_type', 'client')->where('documentable_id', (string) $client->id)->pluck('document_type_id')->all();
        $this->assertSame([$visura->id], $sul($societa), 'la persona giuridica ha la visura, non la carta d\'identità');
        $this->assertSame([$identita->id], $sul($coobbligato));
        $this->assertSame([], $sul($garante), 'il garante non è fra i ruoli dell\'identità e non è una società');
    }

    public function test_la_check_list_crea_i_documenti_sui_client_con_diritto(): void
    {
        $mutuo = TipoProdotto::create(['name' => 'Mutuo']);
        $task = Task::create(['name' => 'Check list Mutui · privati', 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_value' => 'Mutuo', 'tipoprodotto_id' => $mutuo->id, 'trigger_subfield' => 'employment_document_group', 'trigger_subvalue' => 'privati']);
        $identita = DocumentType::create(['name' => 'Carta identità', 'code' => 'CI', 'client_scope' => 'persona_fisica']);
        $busta = DocumentType::create(['name' => 'Busta paga', 'code' => 'BP']);
        DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $identita->id, 'documentable' => 'client', 'client_roles' => ['richiedente', 'coobbligato']]);
        DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $busta->id]);

        $pratica = Pratica::create(['codice_pratica' => 'P-C4', 'upload_at' => now(), 'tipoprodotto_id' => $mutuo->id]);
        $richiedente = Client::create(['name' => 'Rossi', 'is_person' => true]);
        $garante = Client::create(['name' => 'Neri', 'is_person' => true]);
        PraticaClient::create(['pratica_id' => $pratica->getKey(), 'client_id' => $richiedente->id, 'role' => 'richiedente']);
        PraticaClient::create(['pratica_id' => $pratica->getKey(), 'client_id' => $garante->id, 'role' => 'garante']);

        $this->assertSame(2, (new PracticeTaskDocuments)->attachChecklist($pratica, 'privati'));
        $this->assertSame(0, (new PracticeTaskDocuments)->attachChecklist($pratica, 'privati'));
        $this->assertSame([$busta->id], Document::query()->where('documentable_type', 'pratica')->pluck('document_type_id')->all());
        $this->assertSame([$identita->id], Document::query()->where('documentable_type', 'client')->where('documentable_id', (string) $richiedente->id)->pluck('document_type_id')->all());
    }
}
