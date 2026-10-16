<?php

namespace Unico\Core\Documents;

use Illuminate\Support\Collection;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\Pratica;
use Unico\Core\Models\Task;

/**
 * Documenti richiesti per una pratica dai task di pratica collegati al tipo prodotto: check list per gruppo di tipologia
 * lavorativa (fase iniziale), task di stato (es. FASCICOLO COMPLETO per la modulistica da firmare), informativa privacy.
 * I documenti specifici di una banca (`document_types.clienti_id`) valgono solo per le pratiche di quella banca.
 */
class PracticeTaskDocuments
{
    /** Task di pratica (senza trigger) con l'informativa privacy da far firmare al cliente. */
    public const PRIVACY_TASK = 'Informativa privacy';

    /** Tipo documento dell'informativa: `document_url` indica da dove scaricare il modulo. */
    public const PRIVACY_DOCUMENT = 'Informativa privacy';

    /**
     * Documenti dell'informativa privacy da far firmare prima del perfezionamento.
     *
     * @return Collection<int, DocumentRequirement>
     */
    public function privacy(): Collection
    {
        $task = Task::query()
            ->where('taskable', 'pratica')
            ->where('is_active', true)
            ->where('name', self::PRIVACY_TASK)
            ->first();

        return $task === null
            ? collect()
            : $task->requisitiEffettivi();
    }

    /**
     * @return Collection<int, DocumentRequirement>
     */
    public function forProductStatus(string $productName, string $statusCode, int|string|null $bankId = null): Collection
    {
        $task = $this->task($productName, $statusCode);

        // Il catalogo attuale chiama PERFEZIONATA il task dei documenti di perfezionamento.
        if ($task === null && $statusCode === 'PERFEZIONARE') {
            $task = $this->task($productName, 'PERFEZIONATA');
        }

        if ($task === null) {
            return collect();
        }

        return $task->requisitiEffettivi()
            ->filter(fn (DocumentRequirement $requirement): bool => $this->appliesToBank($requirement, $bankId))
            ->values();
    }

    /** I documenti specifici di una banca (`document_types.clienti_id`) valgono solo per le pratiche di quella banca. */
    private function appliesToBank(DocumentRequirement $requirement, int|string|null $bankId): bool
    {
        return $requirement->documentType?->appliesToBank($bankId) ?? true;
    }

    /**
     * Check list del prodotto: il task del gruppo di tipologia lavorativa (pensionati, privati, pubblici, parapubblici,
     * autonomi) oppure, per i prodotti senza gruppi (corporate), il task «Check list» del prodotto.
     *
     * @return Collection<int, DocumentRequirement>
     */
    public function checklist(string $productName, ?string $group, int|string|null $bankId = null): Collection
    {
        $tasks = Task::query()
            ->where('taskable', 'pratica')
            ->where('is_active', true)
            ->delProdotto($productName)
            ->where(function ($query) use ($group): void {
                $query->where(fn ($query) => $query->where('trigger_subfield', 'employment_document_group')->where('trigger_subvalue', $group))
                    ->orWhere(fn ($query) => $query->whereNull('trigger_subfield')->where('name', 'like', 'Check list%'));
            })
            ->get();

        return $tasks
            ->flatMap(fn (Task $task) => $task->requisitiEffettivi())
            ->filter(fn (DocumentRequirement $requirement): bool => $this->appliesToBank($requirement, $bankId))
            ->values();
    }

    /**
     * Documenti da chiedere al cliente nella fase iniziale (perfezionare): la check list del prodotto, senza doppioni
     * (stesso tipo, o stesso documento d'identità/codice fiscale con un altro nome). I documenti letti dall'AI vengono per primi.
     * Contratto, polizza, delibera e modulistica da firmare sono delle fasi successive, con i loro task di stato.
     *
     * @return Collection<int, DocumentRequirement>
     */
    public function forProduct(string $productName, ?string $group, int|string|null $bankId = null): Collection
    {
        $merged = collect();

        foreach ($this->checklist($productName, $group, $bankId) as $requirement) {
            $type = $requirement->documentType;

            if ($type === null) {
                continue;
            }

            $existing = $merged->first(fn (DocumentRequirement $other): bool => $other->document_type_id === $requirement->document_type_id
                || ($type->ai_kind !== null && $other->documentType?->ai_kind === $type->ai_kind));

            if ($existing !== null) {
                $existing->is_required = $existing->is_required || $requirement->is_required;

                continue;
            }

            $merged->push($requirement);
        }

        return $merged
            ->sortBy(fn (DocumentRequirement $requirement): int => $requirement->documentType?->ai_kind === null ? 1 : 0)
            ->values();
    }

    /** Crea sulla pratica i documenti del task di perfezionamento del suo tipo prodotto. */
    public function attachToPractice(Pratica $pratica): int
    {
        $productName = $pratica->tipoprodotto?->name;

        if ($productName === null) {
            return 0;
        }

        $created = 0;

        foreach ($this->forProductStatus($productName, 'PERFEZIONARE') as $requirement) {
            $type = $requirement->documentType;

            if ($type === null) {
                continue;
            }

            foreach ($this->owners($requirement, $pratica) as [$documentableType, $documentableId]) {
                $document = Document::query()->firstOrCreate(
                    [
                        'documentable_type' => $documentableType,
                        'documentable_id' => $documentableId,
                        'document_type_id' => $type->getKey(),
                    ],
                    [
                        'company_id' => is_numeric($pratica->company_id) ? (int) $pratica->company_id : null,
                        'name' => $type->name,
                        'is_template' => (bool) $type->is_template,
                        'is_monitored' => (bool) $type->is_monitored,
                        'doctype' => $type->doctype,
                        'status' => DocumentStatus::PENDING,
                    ],
                );

                if ($document->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * I model su cui nasce il documento richiesto: la pratica, oppure i client della pratica (per `documentable` = client),
     * tutti o solo quelli del ruolo indicato in `client_role`.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function owners(DocumentRequirement $requirement, Pratica $pratica): array
    {
        if ($requirement->documentable !== 'client') {
            return [['pratica', (string) $pratica->getKey()]];
        }

        return $pratica->praticaClients()
            ->when($requirement->client_role !== null, fn ($query) => $query->where('role', $requirement->client_role))
            ->pluck('client_id')
            ->unique()
            ->map(fn ($clientId): array => ['client', (string) $clientId])
            ->all();
    }

    private function task(string $productName, string $statusCode): ?Task
    {
        return Task::query()
            ->where('taskable', 'pratica')
            ->where('is_active', true)
            ->delProdotto($productName)
            ->where('trigger_subfield', 'stato_pratica')
            ->where('trigger_subvalue', $statusCode)
            ->first();
    }
}
