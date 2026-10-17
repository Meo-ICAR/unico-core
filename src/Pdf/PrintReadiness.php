<?php

namespace Unico\Core\Pdf;

use Illuminate\Support\Collection;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Documents\Reminders\DocumentRequestRouter;
use Unico\Core\Models\Client;
use Unico\Core\Models\Document;
use Unico\Core\Models\Pratica;

/**
 * Prima della stampa verifica che i campi obbligatori del modulo (`pdf_module_fields.is_required`)
 * siano valorizzati nel record associato al documento, e porta la fase di `documents.status` a «stampare» quando sono tutti valorizzati, a «dati_mancanti» altrimenti.
 * Vale solo per i documenti ancora da firmare: non firmati e con `document_types.is_signed` vero.
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

        // Il modulo di un cliente si compila con i dati della sua ultima pratica.
        if ($record instanceof Client) {
            $record = app(DocumentRequestRouter::class)->practiceOf($record);
        }

        if ($module === null || ! $module->is_active || ! $record instanceof Pratica) {
            return null;
        }

        $module->loadMissing('pdfModuleFields');
        $required = $module->pdfModuleFields->filter->is_required;

        return $this->data->for($record)->missingAmong($required->flatMap->referencedKeys());
    }

    /**
     * I dati obbligatori mancanti con il loro nome leggibile. Null se il documento non è da controllare.
     *
     * @return array<int, string>|null
     */
    public function missingLabels(Document $document): ?array
    {
        $missing = $this->missing($document);

        return $missing === null ? null : ModuleKeyLabel::many($missing);
    }

    /** Porta il documento a «stampare» (campi completi) o a «dati mancanti» (campi obbligatori non valorizzati) e restituisce se è stampabile. */
    public function refresh(Document $document): bool
    {
        $missing = $this->missing($document);
        $printable = $missing !== null && $missing === [];

        if ($document->status?->isBeforeSending()) {
            $phase = $printable ? DocumentStatus::PRINT : ($missing !== null ? DocumentStatus::MISSING_DATA : $document->status);

            if ($document->status !== $phase) {
                $document->forceFill(['status' => $phase])->saveQuietly();
                $document->syncResponsible();
            }
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
