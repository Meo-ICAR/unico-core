<?php

namespace Unico\Core\Pdf;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use mikehaertl\pdftk\Pdf;
use Unico\Core\Models\PdfModule;
use Unico\Core\Models\PdfModuleField;

/** Compila i campi AcroForm di un modulo PDF con pdftk (fill_form). */
class PdfFormFiller
{
    /** Apre un PDF con il binario pdftk configurato (`unico-core.pdf.pdftk_binary`). */
    public function open(string $absolutePath): Pdf
    {
        return new Pdf($absolutePath, ['command' => config('unico-core.pdf.pdftk_binary', 'pdftk')]);
    }

    /**
     * Rende statici i campi del PDF: i valori compilati restano visibili anche dopo la firma.
     *
     * @throws PdfFormException
     */
    public function flattenContent(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'flat');

        try {
            file_put_contents($path, $content);

            $pdf = $this->open($path)->flatten();
            $flattened = $pdf->toString();

            if ($flattened === false) {
                throw PdfFormException::pdftkFailed($pdf->getError());
            }

            return $flattened;
        } finally {
            @unlink($path);
        }
    }

    /** @return array<string, string> nome campo PDF => valore da scrivere */
    public function buildFieldValues(PdfModule $module, ResolvedModuleData $data): array
    {
        $values = [];

        foreach ($module->pdfModuleFields as $field) {
            if ($field->isCheckbox()) {
                $checked = $this->isCheckboxChecked($field, $data);

                if ($checked !== null) {
                    $values[$field->pdf_field_name] = $checked ? ($field->checkbox_on_value ?: 'Yes') : 'Off';
                }

                continue;
            }

            if ($field->source_key === null) {
                continue;
            }

            $text = ModuleFormatter::format($data->get($field->source_key), $field->formatter);

            if ($text !== '') {
                $values[$field->pdf_field_name] = $text;
            }
        }

        return $values;
    }

    /**
     * @return string contenuto binario del PDF compilato
     *
     * @throws PdfFormException
     */
    public function fill(PdfModule $module, ResolvedModuleData $data, bool $flatten = false): string
    {
        return $this->render($module, $this->buildFieldValues($module, $data), $flatten);
    }

    /**
     * PDF diagnostico: ogni campo testo mostra il proprio nome e le checkbox sono spuntate,
     * così chi mappa vede dove sta ciascun campo sul modulo.
     */
    public function fillDiagnostic(PdfModule $module): string
    {
        $values = [];

        foreach ($module->pdfModuleFields as $field) {
            $values[$field->pdf_field_name] = $field->isCheckbox()
                ? ($field->checkbox_on_value ?: 'Yes')
                : $field->pdf_field_name;
        }

        return $this->render($module, $values, false);
    }

    /** Scrive il PDF diagnostico sul disco dei moduli e ritorna il percorso relativo (contiene solo nomi di campo). */
    public function storeDiagnostic(PdfModule $module): string
    {
        $path = 'module-diagnostica/'.Str::slug($module->name).'.pdf';

        $this->disk()->put($path, $this->fillDiagnostic($module));

        return $path;
    }

    /**
     * Esito della casella: true/false se configurata, null se non c'è nulla da fare.
     * `checkbox_when` (`[!]chiave[=valore]`, `!` nega) ha la precedenza su `source_key`.
     */
    private function isCheckboxChecked(PdfModuleField $field, ResolvedModuleData $data): ?bool
    {
        if (filled($field->checkbox_when)) {
            ['negate' => $negate, 'key' => $key, 'value' => $value] = $field->parseCheckboxWhen();

            if ($key === null) {
                return null;
            }

            $matches = $value === null ? $data->isTruthy($key) : $data->equals($key, $value);

            return $matches !== $negate;
        }

        return $field->source_key !== null ? $data->isTruthy($field->source_key) : null;
    }

    /** @param  array<string, string>  $values */
    private function render(PdfModule $module, array $values, bool $flatten): string
    {
        $disk = $this->disk();

        if (! $disk->exists($module->file_path)) {
            throw PdfFormException::missingTemplate($module->file_path);
        }

        if ($values === [] && ! $flatten) {
            return $disk->get($module->file_path);
        }

        $pdf = $this->open($disk->path($module->file_path));

        if ($values !== []) {
            $pdf->fillForm($values);
        }

        $flatten ? $pdf->flatten() : $pdf->needAppearances();

        $content = $pdf->toString();

        if ($content === false) {
            throw PdfFormException::pdftkFailed($pdf->getError());
        }

        return $content;
    }

    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(config('unico-core.pdf.modules_disk', 'public'));
    }
}
