<?php

namespace Unico\Core\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\TestCase;
use Unico\Core\Models\Company;
use Unico\Core\Models\Pratica;
use Unico\Core\Models\Task;
use Unico\Core\MorphMap;
use Unico\Core\Tests\Support\CoreDatabase;

class TaskConditionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
        Relation::morphMap(MorphMap::all(), merge: true);
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('tasks');
    }

    private function pratica(array $attributes): Pratica
    {
        return (new Pratica)->forceFill($attributes);
    }

    public function test_il_plico_si_applica_solo_se_passano_entrambe_le_condizioni(): void
    {
        $task = new Task(['taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_state' => 'equals', 'trigger_value' => 'Cessione', 'trigger_subfield' => 'stato_pratica', 'trigger_subvalue' => 'PERFEZIONATA']);

        $this->assertTrue($task->matchesConditions($this->pratica(['tipo_prodotto' => 'Cessione', 'stato_pratica' => 'PERFEZIONATA'])));
        $this->assertFalse($task->matchesConditions($this->pratica(['tipo_prodotto' => 'Cessione', 'stato_pratica' => 'APERTA'])));
        $this->assertFalse($task->matchesConditions($this->pratica(['tipo_prodotto' => 'Mutuo', 'stato_pratica' => 'PERFEZIONATA'])));
    }

    public function test_un_record_di_un_altro_model_e_l_esclusione_non_passano(): void
    {
        $task = new Task(['taskable' => 'pratica', 'exclude_field' => 'stato_pratica', 'exclude_state' => 'filled']);

        $this->assertTrue($task->matchesConditions($this->pratica([])));
        $this->assertFalse($task->matchesConditions($this->pratica(['stato_pratica' => 'APERTA'])));
        $this->assertFalse($task->matchesConditions(new Company));
    }

    public function test_applicabili_a_prende_i_plichi_radice_attivi_della_company(): void
    {
        $ok = Task::create(['name' => 'Radice', 'taskable' => 'pratica', 'is_active' => true]);
        Task::create(['name' => 'Figlio', 'taskable' => 'pratica', 'is_active' => true, 'parent_id' => $ok->id]);
        Task::create(['name' => 'Spento', 'taskable' => 'pratica', 'is_active' => false]);
        Task::create(['name' => 'Altro model', 'taskable' => 'company', 'is_active' => true]);
        Task::create(['name' => 'Altra regola', 'taskable' => 'pratica', 'is_active' => true, 'trigger_field' => 'stato_pratica', 'trigger_state' => 'filled']);

        $this->assertSame([$ok->id], Task::applicabiliA($this->pratica([]))->pluck('id')->all());
    }

    public function test_popola_il_task_con_i_documenti_del_subtask_senza_duplicare(): void
    {
        $comuni = Task::create(['name' => 'Documenti comuni', 'taskable' => 'pratica']);
        $task = Task::create(['name' => 'Mutuo', 'taskable' => 'pratica']);
        $informativa = \Unico\Core\Models\DocumentType::create(['name' => 'Informativa', 'code' => 'INF']);
        $tegm = \Unico\Core\Models\DocumentType::create(['name' => 'TEGM', 'code' => 'TEGM']);
        \Unico\Core\Models\DocumentRequirement::create(['task_id' => $comuni->id, 'document_type_id' => $informativa->id, 'sort_order' => 1, 'documentable' => 'client', 'client_roles' => ['richiedente']]);
        \Unico\Core\Models\DocumentRequirement::create(['task_id' => $comuni->id, 'document_type_id' => $tegm->id, 'sort_order' => 2, 'is_required' => false]);
        \Unico\Core\Models\DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $tegm->id, 'sort_order' => 1]);

        $this->assertSame(1, $task->importRequirementsFrom($comuni));
        $this->assertSame(0, $task->importRequirementsFrom($comuni));
        $this->assertSame(0, $task->importRequirementsFrom($task));

        $copiato = $task->documentRequirements()->where('document_type_id', $informativa->id)->firstOrFail();
        $this->assertSame('client', $copiato->documentable);
        $this->assertSame(['richiedente'], $copiato->client_roles);
        $this->assertSame(2, $copiato->sort_order);
    }
}
