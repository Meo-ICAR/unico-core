<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\Task;
use Unico\Core\Seeders\ChecklistPerfezionamentoSeeder;
use Unico\Core\Tests\Support\CoreDatabase;

class ChecklistRacesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        foreach (['document_requirements', 'tasks', 'document_types', 'clientis', 'tipoprodotto'] as $tabella) {
            CoreDatabase::truncate($tabella);
        }
    }

    /** I tipi documento dei fascicoli Races arrivano dai moduli PDF, non dal seeder. */
    private function creaFascicoli(): void
    {
        foreach ([
            'Races VERS. 03_2026 - Fascicolo completo retail CQ compilabile',
            'Races VERS. 03_2026 - Fascicolo completo retail MUTUI compilabile',
            'Races VERS. 03_2026 - Fascicolo completo retail Prestiti Personali compilabile',
            'Races VERS. 06_2025 - Fascicolo completo Corporate compilabile',
        ] as $nome) {
            DocumentType::query()->create(['name' => $nome, 'code' => strtoupper(substr(md5($nome), 0, 10))]);
        }
    }

    /** @return list<string> */
    private function nomi(string $task): array
    {
        return Task::query()->where('name', $task)->firstOrFail()->requisitiEffettivi()
            ->map(fn (DocumentRequirement $requisito): string => $requisito->documentType->name)->sort()->values()->all();
    }

    public function test_il_fascicolo_races_e_un_task_base_inattivo_ereditato_dai_task_dei_prodotti(): void
    {
        $this->creaFascicoli();
        (new ChecklistPerfezionamentoSeeder)->run();

        $base = Task::query()->where('name', 'Fascicolo Races CQ')->firstOrFail();
        $this->assertFalse((bool) $base->is_active, 'Il task base non è un plico a sé.');
        $this->assertSame(1, $base->documentRequirements()->count());

        foreach (['Cessione', 'Delega'] as $prodotto) {
            $figlio = Task::query()->where('name', "{$prodotto} · FASCICOLO COMPLETO")->firstOrFail();
            $this->assertSame($base->getKey(), $figlio->inherits_task_id);
            $this->assertContains('Races VERS. 03_2026 - Fascicolo completo retail CQ compilabile', $this->nomi("{$prodotto} · FASCICOLO COMPLETO"));
        }

        $this->assertSame(0, DocumentRequirement::query()->whereIn('task_id', Task::query()->whereIn('name', ['Cessione · FASCICOLO COMPLETO', 'Delega · FASCICOLO COMPLETO'])->pluck('id'))
            ->whereHas('documentType', fn ($query) => $query->where('name', 'like', '%Fascicolo completo retail CQ%'))->count(), 'Il documento sta solo nel task base.');
        $this->assertContains('Allegato B (delega)', $this->nomi('Delega · FASCICOLO COMPLETO'));

        $fascicolo = Task::query()->where('name', 'Cessione · FASCICOLO COMPLETO')->firstOrFail()->requisitiEffettivi()->first(fn (DocumentRequirement $requisito): bool => str_contains($requisito->documentType->name, 'retail CQ'));
        $this->assertSame('invio_banca', $fascicolo->phase, 'La fase resta quella del task base.');
    }

    public function test_i_prodotti_aziendali_e_il_mutuo_ristrutturazione_ereditano_a_catena(): void
    {
        $this->creaFascicoli();
        (new ChecklistPerfezionamentoSeeder)->run();

        foreach (['LEASING', 'CHIROGRAFARIO', 'Microcredito'] as $prodotto) {
            $this->assertSame(['Races VERS. 06_2025 - Fascicolo completo Corporate compilabile'], $this->nomi("{$prodotto} · FASCICOLO COMPLETO"));
        }

        $this->assertContains('Races VERS. 03_2026 - Fascicolo completo retail MUTUI compilabile', $this->nomi('Mutuo ristrutturazione · FASCICOLO COMPLETO'));
    }

    public function test_ripetere_il_seeder_non_cambia_i_requisiti_effettivi(): void
    {
        $this->creaFascicoli();
        (new ChecklistPerfezionamentoSeeder)->run();
        $prima = $this->nomi('Cessione · FASCICOLO COMPLETO');
        $taskPrima = Task::query()->count();

        (new ChecklistPerfezionamentoSeeder)->run();

        $this->assertSame($prima, $this->nomi('Cessione · FASCICOLO COMPLETO'));
        $this->assertSame($taskPrima, Task::query()->count());
        $this->assertSame(1, Task::query()->where('name', 'Fascicolo Races CQ')->count());
    }
}
