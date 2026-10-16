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
        DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $privacy->id, 'documentable' => 'client', 'client_role' => 'garante']);

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
}
