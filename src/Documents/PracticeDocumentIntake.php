<?php

namespace Unico\Core\Documents;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Unico\Core\Documents\Listeners\AnalyzeUploadedDocument;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Models\Document;
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
            $this->replacePlaceholders($pratica, $document);

            if (config('unico-core.practice_intake.populate_client', true)) {
                $this->populator->apply($pratica, $document);
            }
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
        $document = Document::query()
            ->where('documentable_type', 'pratica')
            ->where('documentable_id', (string) $pratica->getKey())
            ->where('document_type_id', $documentTypeId)
            ->first();

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
        $present = Document::query()
            ->where('documentable_type', 'pratica')
            ->where('documentable_id', (string) $pratica->getKey())
            ->whereIn('document_type_id', $expected->pluck('id'))
            ->whereHas('media', fn ($query) => $query->where('collection_name', Document::COLLECTION))
            ->pluck('document_type_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $expected->mapWithKeys(fn (DocumentType $type): array => [$type->getKey() => in_array($type->getKey(), $present, true)])->all();
    }

    /** I documenti «in attesa» creati dal task per lo stesso tipo, senza file, lasciano il posto a quello caricato. */
    private function replacePlaceholders(Pratica $pratica, Document $document): void
    {
        Document::query()
            ->where('documentable_type', 'pratica')
            ->where('documentable_id', (string) $pratica->getKey())
            ->where('document_type_id', $document->document_type_id)
            ->whereKeyNot($document->getKey())
            ->whereDoesntHave('media')
            ->get()
            ->each->delete();
    }
}
