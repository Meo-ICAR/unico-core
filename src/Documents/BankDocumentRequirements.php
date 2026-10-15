<?php

namespace Unico\Core\Documents;

use Illuminate\Support\Collection;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\Task;

/**
 * Collega un documento specifico di una banca (`document_types.clienti_id`) ai task dei prodotti: il trigger del task
 * (`tipo_prodotto` = nome del prodotto) dice per quale prodotto vale, la banca dice per quali pratiche
 * (vedi {@see PracticeTaskDocuments}).
 */
class BankDocumentRequirements
{
    /** Documenti da chiedere al cliente: tutte le check list del prodotto. */
    public const CHECKLIST = 'checklist';

    /** Modulistica da stampare e firmare: il task di stato FASCICOLO COMPLETO del prodotto. */
    public const FASCICOLO = 'fascicolo';

    /** @return array<string, string> */
    public static function fasi(): array
    {
        return [self::CHECKLIST => 'Documenti da chiedere al cliente', self::FASCICOLO => 'Modulistica da firmare'];
    }

    /**
     * Rende i requisiti del documento esattamente quelli dei prodotti indicati per la fase.
     *
     * @param  array<int, string>  $prodotti  nomi dei tipi prodotto
     */
    public function sync(DocumentType $type, array $prodotti, string $fase, bool $obbligatorio = true): void
    {
        $tasks = collect($prodotti)->flatMap(fn (string $prodotto): Collection => $this->tasks($prodotto, $fase))->unique('id');

        DocumentRequirement::query()
            ->where('document_type_id', $type->getKey())
            ->whereNotNull('task_id')
            ->whereNotIn('task_id', $tasks->pluck('id'))
            ->delete();

        foreach ($tasks as $task) {
            DocumentRequirement::query()->updateOrCreate(
                ['document_type_id' => $type->getKey(), 'task_id' => $task->getKey()],
                ['is_required' => $obbligatorio, 'sort_order' => (int) $task->documentRequirements()->max('sort_order') + 1],
            );
        }
    }

    /** Prodotti (e fase) per cui il documento è richiesto, ricavati dai trigger dei task. */
    public function prodotti(DocumentType $type): array
    {
        $tasks = Task::query()->whereIn('id', DocumentRequirement::query()->where('document_type_id', $type->getKey())->whereNotNull('task_id')->pluck('task_id'))->get();

        return [
            'prodotti' => $tasks->where('trigger_field', 'tipo_prodotto')->pluck('trigger_value')->unique()->values()->all(),
            'fase' => $tasks->contains(fn (Task $task): bool => $task->trigger_subfield === 'stato_pratica') ? self::FASCICOLO : self::CHECKLIST,
        ];
    }

    /** @return Collection<int, Task> */
    private function tasks(string $prodotto, string $fase): Collection
    {
        return Task::query()
            ->where('taskable', 'pratica')
            ->where('is_active', true)
            ->where('trigger_field', 'tipo_prodotto')
            ->where('trigger_value', $prodotto)
            ->when(
                $fase === self::FASCICOLO,
                fn ($query) => $query->where('trigger_subfield', 'stato_pratica')->where('trigger_subvalue', 'FASCICOLO COMPLETO'),
                fn ($query) => $query->where(fn ($q) => $q->where('trigger_subfield', 'employment_document_group')->orWhere(fn ($w) => $w->whereNull('trigger_subfield')->where('name', 'like', 'Check list%'))),
            )
            ->get();
    }
}
