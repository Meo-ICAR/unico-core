<?php

namespace Unico\Core\Tests;

use Illuminate\Http\UploadedFile;
use mikehaertl\pdftk\Pdf;
use PHPUnit\Framework\TestCase;
use Unico\Core\Documents\Contracts\DocumentSegmenter;
use Unico\Core\Documents\NullDocumentSegmenter;
use Unico\Core\Documents\UploadExpander;
use Unico\Core\Tests\Support\CoreDatabase;
use ZipArchive;

class UploadExpanderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    private function pdf(int $pages): string
    {
        $one = tempnam(sys_get_temp_dir(), 'one');
        file_put_contents($one, "%PDF-1.1\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n");
        $out = tempnam(sys_get_temp_dir(), 'pdf');
        (new Pdf(array_fill(0, $pages, $one), ['command' => 'pdftk']))->cat()->saveAs($out);

        return $out;
    }

    private function segmenter(?array $segments): DocumentSegmenter
    {
        return new class($segments) implements DocumentSegmenter
        {
            public function __construct(private ?array $segments) {}

            public function enabled(): bool
            {
                return true;
            }

            public function segments(string $pdfBytes, int $pages): ?array
            {
                return $this->segments;
            }
        };
    }

    public function test_uno_zip_si_apre_e_ogni_documento_e_un_file(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFile($this->pdf(1), 'carta-identita.pdf');
        $zip->addFile($this->pdf(1), 'cartella/busta-paga.pdf');
        $zip->addFromString('note.txt', 'non è un documento');
        $zip->addFromString('__MACOSX/._carta-identita.pdf', 'scarto');
        $zip->close();

        $files = (new UploadExpander(new NullDocumentSegmenter))->expand(new UploadedFile($zipPath, 'pratica.zip', 'application/zip', null, true));

        $this->assertSame(['carta-identita.pdf', 'busta-paga.pdf'], array_map(fn (UploadedFile $file): string => $file->getClientOriginalName(), $files));
    }

    public function test_un_pdf_con_piu_documenti_si_divide_secondo_l_ai(): void
    {
        $segmenter = $this->segmenter([['from' => 1, 'to' => 2, 'label' => 'Carta d\'identità'], ['from' => 3, 'to' => 3, 'label' => 'Busta paga']]);

        $files = (new UploadExpander($segmenter))->expand(new UploadedFile($this->pdf(3), 'scansione.pdf', 'application/pdf', null, true));

        $this->assertCount(2, $files);
        $this->assertSame('scansione (1) - Carta d\'identità.pdf', $files[0]->getClientOriginalName());
        $this->assertSame(2, (int) (new Pdf($files[0]->getRealPath(), ['command' => 'pdftk']))->getData()["NumberOfPages"]);
        $this->assertSame(1, (int) (new Pdf($files[1]->getRealPath(), ['command' => 'pdftk']))->getData()["NumberOfPages"]);
    }

    public function test_se_l_ai_non_copre_tutte_le_pagine_o_sbaglia_il_file_resta_intero(): void
    {
        $originale = new UploadedFile($this->pdf(3), 'scansione.pdf', 'application/pdf', null, true);

        foreach ([null, [['from' => 1, 'to' => 3, 'label' => null]], [['from' => 1, 'to' => 1, 'label' => null], ['from' => 3, 'to' => 3, 'label' => null]]] as $segments) {
            $files = (new UploadExpander($this->segmenter($segments)))->expand($originale);

            $this->assertCount(1, $files);
            $this->assertSame('scansione.pdf', $files[0]->getClientOriginalName());
        }
    }
}
