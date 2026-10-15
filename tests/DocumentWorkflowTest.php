<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Documents\TechnicalVerifier;
use Unico\Core\Enums\DocumentAnomaly;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\Task;
use Unico\Core\Models\TipoProdotto;
use Unico\Core\Tests\Support\CoreDatabase;

class DocumentWorkflowTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('tasks', 'document_requirements', 'document_types', 'tipoprodotto');
    }

    public function test_gli_esiti_dell_istruttoria_sono_ok_respinto_anomalo_na_integrativo(): void
    {
        $this->assertSame(
            ['approvato' => 'OK', 'respinto' => 'Respinto', 'anomalo' => 'Anomalo', 'na' => 'N/A', 'integrativo' => 'Integrativo'],
            DocumentStatus::istruttoria(),
        );
    }

    public function test_un_documento_soddisfa_la_richiesta_solo_se_caricato_ok_provvisorio_o_na(): void
    {
        foreach ([DocumentStatus::UPLOADED, DocumentStatus::APPROVED, DocumentStatus::PROVISIONAL, DocumentStatus::NA] as $status) {
            $this->assertTrue($status->soddisfa(), $status->value);
        }

        foreach ([DocumentStatus::PENDING, DocumentStatus::REJECTED, DocumentStatus::ANOMALY, DocumentStatus::SUPPLEMENTARY, DocumentStatus::EXPIRED] as $status) {
            $this->assertFalse($status->soddisfa(), $status->value);
        }
    }

    public function test_un_documento_emesso_oltre_l_eta_massima_del_tipo_e_un_anomalia(): void
    {
        $tipo = DocumentType::create(['name' => 'Visura camerale', 'code' => 'VC', 'max_age_days' => 90]);
        $verifier = new TechnicalVerifier;

        $vecchio = new Document(['document_type_id' => $tipo->id, 'emitted_at' => now()->subDays(120)]);
        $recente = new Document(['document_type_id' => $tipo->id, 'emitted_at' => now()->subDays(30)]);

        $this->assertContains(DocumentAnomaly::TooOld->value, $verifier->check($vecchio, null)['anomalies']);
        $this->assertNotContains(DocumentAnomaly::TooOld->value, $verifier->check($recente, null)['anomalies']);
    }

    public function test_un_task_figlio_eredita_i_documenti_del_padre_e_aggiunge_i_propri(): void
    {
        $mutuo = TipoProdotto::create(['name' => 'Mutuo', 'tipo_prodotto' => 'Mutuo']);
        $ristrutturazione = TipoProdotto::create(['name' => 'Mutuo ristrutturazione', 'tipo_prodotto' => 'Mutuo ristrutturazione']);
        $identita = DocumentType::create(['name' => 'Carta identità', 'code' => 'CI']);
        $buste = DocumentType::create(['name' => 'Busta paga', 'code' => 'BP']);
        $preventivi = DocumentType::create(['name' => 'Preventivi lavori', 'code' => 'PL']);

        $padre = Task::create(['name' => 'Check list Mutui', 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_value' => 'Mutuo', 'tipoprodotto_id' => $mutuo->id, 'is_active' => true]);
        $figlio = Task::create(['name' => 'Check list Mutui ristrutturazione', 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_value' => 'Mutuo ristrutturazione', 'tipoprodotto_id' => $ristrutturazione->id, 'parent_id' => $padre->id, 'is_active' => true]);

        DocumentRequirement::create(['task_id' => $padre->id, 'document_type_id' => $identita->id, 'is_required' => true, 'sort_order' => 1]);
        DocumentRequirement::create(['task_id' => $padre->id, 'document_type_id' => $buste->id, 'is_required' => true, 'sort_order' => 2]);
        DocumentRequirement::create(['task_id' => $figlio->id, 'document_type_id' => $preventivi->id, 'is_required' => true, 'sort_order' => 1]);
        // Un requisito del figlio con lo stesso tipo sostituisce quello del padre.
        DocumentRequirement::create(['task_id' => $figlio->id, 'document_type_id' => $buste->id, 'is_required' => false, 'sort_order' => 2]);

        $effettivi = $figlio->requisitiEffettivi()->keyBy('document_type_id');

        $this->assertEqualsCanonicalizing([$identita->id, $buste->id, $preventivi->id], $effettivi->keys()->all());
        $this->assertFalse($effettivi[$buste->id]->is_required);
        $this->assertCount(2, $padre->requisitiEffettivi());
    }

    public function test_il_prodotto_si_trova_per_riferimento_o_per_nome(): void
    {
        $mutuo = TipoProdotto::create(['name' => 'Mutuo', 'tipo_prodotto' => 'Mutuo']);
        $conId = Task::create(['name' => 'Per id', 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_value' => 'Vecchio nome', 'tipoprodotto_id' => $mutuo->id]);
        $perNome = Task::create(['name' => 'Per nome', 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_value' => 'Mutuo']);
        Task::create(['name' => 'Altro', 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_value' => 'Leasing']);

        $this->assertEqualsCanonicalizing([$conId->id, $perNome->id], Task::query()->delProdotto('Mutuo')->pluck('id')->all());
    }
}
