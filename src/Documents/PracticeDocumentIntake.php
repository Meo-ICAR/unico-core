<?php

namespace Unico\Core\Documents;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Unico\Core\Documents\Listeners\AnalyzeUploadedDocument;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Models\Client;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\Pratica;

/**
 * Carica un file su una pratica senza che l'utente dica di che documento si tratta: il tipo si riconosce fra i
 * documenti attesi (regex sul nome del file, poi AI), si leggono i dati e si applicano i controlli del tipo.
 * Se il file non corrisponde a nessun documento atteso resta sulla pratica «da verificare».
 */
class PracticeDocumentIntake
{
    public function __construct(
        private DocumentPipeline $pipeline,
        private PracticeClientPopulator $populator,
    ) {}

    /**
     * @param  Collection<int, DocumentType>  $expected  tipi documento attesi (es. quelli del task)
     */
    public function intake(Pratica $pratica, UploadedFile $file, Collection $expected, ?Authenticatable $user = null): IntakeResult
    {
        $document = Document::query()->create([
            'company_id' => $pratica->company_id,
            'documentable_type' => 'pratica',
            'documentable_id' => (string) $pratica->getKey(),
            'name' => $file->getClientOriginalName(),
            'status' => DocumentStatus::PENDING,
            'user_id' => $user?->getAuthIdentifier(),
            'uploaded_by' => $user?->getAuthIdentifier(),
        ]);

        AnalyzeUploadedDocument::suspendedWhile(fn () => $document->addMedia($file->getRealPath())
            ->usingFileName($file->getClientOriginalName())
            ->toMediaCollection(Document::COLLECTION));

        // Documenti numerati (Busta paga 1, 2, 3 con lo stesso codegroup): si riconosce la famiglia con un solo candidato,
        // poi il file va al primo numero ancora libero.
        $candidates = $expected->groupBy(fn (DocumentType $type): string => filled($type->codegroup) ? 'g:'.$type->codegroup : 'id:'.$type->getKey())
            ->map(fn (Collection $group): DocumentType => $group->first())
            ->values();

        $result = $this->pipeline->run($document->fresh(), ['classify' => true, 'candidates' => $candidates]);
        $document = $result->document->fresh('documentType');
        $this->assignFreeSlot($pratica, $document, $expected);

        if ($document->status === DocumentStatus::PENDING) {
            $document->update(['status' => DocumentStatus::UPLOADED]);
        }

        $type = $document->documentType !== null && $expected->contains('id', $document->document_type_id) ? $document->documentType : null;

        if ($type !== null) {
            $client = config('unico-core.practice_intake.populate_client', true) ? $this->populator->apply($pratica, $document) : null;

            $this->placeOnOwner($pratica, $document, $client);
        }

        return new IntakeResult($file->getClientOriginalName(), $document->fresh('documentType'), $type, $result->outcome, $result->issues);
    }

    /** Dentro una famiglia di documenti numerati il file prende il primo numero senza file. */
    private function assignFreeSlot(Pratica $pratica, Document $document, Collection $expected): void
    {
        $type = $document->documentType;

        if ($type === null || blank($type->codegroup)) {
            return;
        }

        $family = $expected->where('codegroup', $type->codegroup)->values();
        $taken = Document::query()
            ->where('documentable_type', 'pratica')
            ->where('documentable_id', (string) $pratica->getKey())
            ->whereIn('document_type_id', $family->pluck('id'))
            ->whereKeyNot($document->getKey())
            ->whereHas('media', fn ($query) => $query->where('collection_name', Document::COLLECTION))
            ->pluck('document_type_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $free = $family->first(fn (DocumentType $slot): bool => ! in_array($slot->getKey(), $taken, true));

        if ($free !== null && $free->getKey() !== $document->document_type_id) {
            $document->forceFill(['document_type_id' => $free->getKey()])->save();
            $document->unsetRelation('documentType');
        }
    }

    /**
     * Carica un file per un tipo documento già noto (es. l'informativa firmata): riusa il documento in attesa dello stesso tipo
     * se c'è, archivia il file e lancia l'analisi con i candidati indicati (regex/AI ne confermano il contenuto).
     *
     * @param  Collection<int, DocumentType>  $candidates
     */
    public function storeTyped(Pratica $pratica, UploadedFile $file, int $documentTypeId, Collection $candidates, ?Authenticatable $user = null): Document
    {
        $document = $this->placeholderFor($pratica, $documentTypeId);

        if ($document === null) {
            $document = Document::query()->create([
                'company_id' => $pratica->company_id,
                'documentable_type' => 'pratica',
                'documentable_id' => (string) $pratica->getKey(),
                'document_type_id' => $documentTypeId,
                'name' => $file->getClientOriginalName(),
                'status' => DocumentStatus::PENDING,
                'user_id' => $user?->getAuthIdentifier(),
                'uploaded_by' => $user?->getAuthIdentifier(),
            ]);
        } else {
            $document->forceFill([
                'user_id' => $user?->getAuthIdentifier() ?? $document->user_id,
                'uploaded_by' => $user?->getAuthIdentifier() ?? $document->uploaded_by,
            ])->save();
        }

        AnalyzeUploadedDocument::suspendedWhile(fn () => $document->addMedia($file->getRealPath())
            ->usingFileName($file->getClientOriginalName())
            ->toMediaCollection(Document::COLLECTION));

        $this->pipeline->run($document->fresh(), ['classify' => true, 'candidates' => $candidates]);
        $document = $document->fresh('documentType');

        if ($document->documentable_type === 'pratica') {
            $this->placeOnOwner($pratica, $document, null);
            $document = $document->fresh('documentType');
        }

        if ($document->status === DocumentStatus::PENDING && $document->getFirstMedia(Document::COLLECTION) !== null) {
            $document->update(['status' => DocumentStatus::UPLOADED]);
        }

        return $document->fresh('documentType');
    }

    /**
     * Stato dei documenti attesi: per ciascuno se la pratica ne ha già uno con un file.
     *
     * @param  Collection<int, DocumentType>  $expected
     * @return array<int, bool> id del tipo => ricevuto
     */
    public function received(Pratica $pratica, Collection $expected): array
    {
        $requirements = $this->clientRequirements($expected->pluck('id')->all());

        return $expected->mapWithKeys(function (DocumentType $type) use ($pratica, $requirements): array {
            $owners = $this->clientIdsFor($pratica, $requirements, (int) $type->getKey());

            $query = Document::query()->where('document_type_id', $type->getKey())->whereHas('media', fn ($query) => $query->where('collection_name', Document::COLLECTION));

            if ($owners === []) {
                $received = $query->where('documentable_type', 'pratica')->where('documentable_id', (string) $pratica->getKey())->exists();
            } else {
                $withFile = $query->where('documentable_type', 'client')->whereIn('documentable_id', $owners)->pluck('documentable_id')->map(fn (mixed $id): string => (string) $id)->all();
                $received = array_diff($owners, $withFile) === [];
            }

            return [$type->getKey() => $received];
        })->all();
    }

    /**
     * Requisiti dei tipi indicati che nascono sul client della pratica.
     *
     * @param  array<int, int|string>  $typeIds
     * @return Collection<int, DocumentRequirement>
     */
    private function clientRequirements(array $typeIds): Collection
    {
        return DocumentRequirement::query()
            ->where('documentable', 'client')
            ->whereNotNull('task_id')
            ->whereIn('document_type_id', $typeIds)
            ->with('documentType')
            ->get();
    }

    /**
     * I client della pratica a cui spetta il documento di quel tipo, secondo ruoli e tipo persona dei requisiti.
     *
     * @param  Collection<int, DocumentRequirement>  $requirements
     * @return list<string>
     */
    private function clientIdsFor(Pratica $pratica, Collection $requirements, int $typeId): array
    {
        return $requirements->where('document_type_id', $typeId)
            ->flatMap(fn (DocumentRequirement $requirement): array => $requirement->ownersFor($pratica, 'pratica'))
            ->filter(fn (array $owner): bool => $owner[0] === 'client')
            ->map(fn (array $owner): string => $owner[1])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Il documento caricato di un tipo che spetta ai client della pratica va al client indicato (quello letto dal documento)
     * oppure al primo avente diritto ancora senza file; se non c'è nessuno resta sulla pratica. I segnaposto vuoti dello stesso
     * tipo lasciano il posto al file.
     */
    private function placeOnOwner(Pratica $pratica, Document $document, ?Client $client): void
    {
        $typeId = (int) $document->document_type_id;
        $requirements = $this->clientRequirements([$typeId]);
        $owners = $this->clientIdsFor($pratica, $requirements, $typeId);

        if ($owners !== []) {
            $taken = Document::query()
                ->where('documentable_type', 'client')
                ->whereIn('documentable_id', $owners)
                ->where('document_type_id', $typeId)
                ->whereKeyNot($document->getKey())
                ->whereHas('media', fn ($query) => $query->where('collection_name', Document::COLLECTION))
                ->pluck('documentable_id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all();

            $target = $client !== null && in_array((string) $client->getKey(), $owners, true) && ! in_array((string) $client->getKey(), $taken, true)
                ? (string) $client->getKey()
                : collect($owners)->first(fn (string $id): bool => ! in_array($id, $taken, true));

            if ($target !== null) {
                $this->removePlaceholders('client', $target, $document);
                $document->forceFill(['documentable_type' => 'client', 'documentable_id' => $target])->save();

                return;
            }
        }

        $this->removePlaceholders('pratica', (string) $pratica->getKey(), $document);
    }

    /** Il documento in attesa (senza file) del tipo: sulla pratica, o sul client avente diritto; altrimenti uno già presente sulla pratica. */
    private function placeholderFor(Pratica $pratica, int $typeId): ?Document
    {
        $owners = $this->clientIdsFor($pratica, $this->clientRequirements([$typeId]), $typeId);

        $query = Document::query()->where('document_type_id', $typeId)->where(function ($query) use ($pratica, $owners): void {
            $query->where(fn ($q) => $q->where('documentable_type', 'pratica')->where('documentable_id', (string) $pratica->getKey()))
                ->when($owners !== [], fn ($q) => $q->orWhere(fn ($w) => $w->where('documentable_type', 'client')->whereIn('documentable_id', $owners)));
        });

        return (clone $query)->whereDoesntHave('media')->orderBy('id')->first() ?? (clone $query)->where('documentable_type', 'pratica')->first();
    }

    /** I documenti «in attesa» creati dal task per lo stesso tipo, senza file, lasciano il posto a quello caricato. */
    private function removePlaceholders(string $ownerType, string $ownerId, Document $document): void
    {
        Document::query()
            ->where('documentable_type', $ownerType)
            ->where('documentable_id', $ownerId)
            ->where('document_type_id', $document->document_type_id)
            ->whereKeyNot($document->getKey())
            ->whereDoesntHave('media')
            ->get()
            ->each->delete();
    }
}
