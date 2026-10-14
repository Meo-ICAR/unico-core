<?php

namespace Unico\Core\Pdf;

use Unico\Core\Models\Document;
use Unico\Core\Models\PdfModule;
use Unico\Core\Models\Pratica;

/**
 * Stampa un modulo PDF: risolve i dati della pratica e compila l'AcroForm con pdftk.
 */
class ModulePrinter
{
    public function __construct(
        private PdfFormFiller $filler,
        private PracticeModuleData $data,
        private PrintReadiness $readiness,
    ) {}

    /**
     * @throws PdfFormException
     */
    public function render(PdfModule $module, ResolvedModuleData $data, bool $flatten = false): string
    {
        $module->loadMissing('pdfModuleFields');

        return $this->filler->fill($module, $data, $flatten);
    }

    /**
     * PDF compilato con i dati della pratica, pronto da stampare.
     *
     * @throws PdfFormException
     */
    public function print(Pratica $pratica, PdfModule $module): string
    {
        return $this->render($module, $this->data->for($pratica));
    }

    /**
     * Stampa il modulo associato a un documento da firmare, solo se i campi obbligatori sono valorizzati.
     *
     * @throws PdfFormException se il documento non è stampabile
     */
    public function printDocument(Document $document): string
    {
        if (! $this->readiness->refresh($document)) {
            throw PdfFormException::notPrintable($this->readiness->missing($document) ?? []);
        }

        return $this->print($document->documentable, $document->documentType->pdfModule);
    }

    /**
     * Stampa il modulo (id) della pratica con quel codice. Null se pratica o modulo non ci sono.
     *
     * @throws PdfFormException
     */
    public function printByReference(?string $reference, int|string $moduleId): ?string
    {
        if (blank($reference)) {
            return null;
        }

        $pratica = Pratica::query()->where('codice_pratica', $reference)->first();
        $module = PdfModule::query()->find($moduleId);

        if ($pratica === null || $module === null || ! $module->is_active) {
            return null;
        }

        return $this->print($pratica, $module);
    }
}
