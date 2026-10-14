<?php

namespace Unico\Core\Pdf;

use Illuminate\Support\Collection;
use Unico\Core\Models\Document;
use Unico\Core\Models\Pratica;

/**
 * Prima della stampa verifica che i campi obbligatori del modulo (`pdf_module_fields.is_required`)
 * siano valorizzati nel record associato al documento, e aggiorna `documents.is_printable`.
 * Vale solo per i documenti ancora da firmare: `documents.is_signed` falso e `document_types.is_signed` vero.
 */
class PrintReadiness
{
    public function __construct(private PracticeModuleData $data) {}

    /** Il documento è un modulo ancora da firmare. */
    public function awaitsSignature(Document $document): bool
    {
        $document->loadMissing('documentType');

        return ! $document->is_signed && (bool) $document->documentType?->is_signed;
    }

    /**
     * Chiavi obbligatorie non valorizzate. Null se il documento non è da controllare
     * (già firmato, tipo senza firma) o non ha un modulo attivo / un record da cui leggere i dati.
     *
     * @return array<int, string>|null
     */
    public function missing(Document $document): ?array
    {
        if (! $this->awaitsSignature($document)) {
            return null;
        }

        $module = $document->documentType->pdfModule;
        $record = $document->documentable;

        if ($module === null || ! $module->is_active || ! $record instanceof Pratica) {
            return null;
        }

        $module->loadMissing('pdfModuleFields');
        $required = $module->pdfModuleFields->filter->is_required;

        return $this->data->for($record)->missingAmong($required->flatMap->referencedKeys());
    }

    /** Aggiorna `is_printable` e restituisce il nuovo valore. */
    public function refresh(Document $document): bool
    {
        $missing = $this->missing($document);
        $printable = $missing !== null && $missing === [];

        if ($document->is_printable !== $printable) {
            $document->forceFill(['is_printable' => $printable])->saveQuietly();
        }

        return $printable;
    }

    /**
     * Controlla tutti i documenti da firmare di un record (es. una pratica) prima di stampare.
     *
     * @return Collection<int, Document> i documenti stampabili
     */
    public function scan(Pratica $record): Collection
    {
        return Document::query()
            ->where('documentable_type', $record->getMorphClass())
            ->where('documentable_id', $record->getKey())
            ->get()
            ->filter(fn (Document $document) => $this->refresh($document))->values();
    }
}
