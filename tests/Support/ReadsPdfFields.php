<?php

namespace Unico\Core\Tests\Support;

use mikehaertl\pdftk\Pdf;

trait ReadsPdfFields
{
    protected function pdftkAvailable(): bool
    {
        return trim((string) shell_exec('command -v pdftk 2>/dev/null')) !== '';
    }

    protected function skipUnlessPdftk(): void
    {
        if (! $this->pdftkAvailable()) {
            $this->markTestSkipped('pdftk non installato.');
        }
    }

    protected function fixturePdfContents(): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/pdf/modulo-prova.pdf');
    }

    /**
     * Legge i campi di un PDF (contenuto binario) come mappa nome => valore.
     *
     * @return array<string, string|null>
     */
    protected function readPdfFields(string $pdfBytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'pdfread');
        file_put_contents($path, $pdfBytes);

        $dataFields = (new Pdf($path, ['command' => 'pdftk']))->getDataFields();
        @unlink($path);

        $fields = [];

        foreach ($dataFields === false ? [] : $dataFields->__toArray() as $field) {
            $fields[$field['FieldName']] = $field['FieldValue'] ?? null;
        }

        return $fields;
    }
}
