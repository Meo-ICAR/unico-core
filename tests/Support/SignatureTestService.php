<?php

namespace Unico\Core\Tests\Support;

use Unico\Core\Models\Document;
use Unico\Core\Signature\SignatureRequestService;

/** Il servizio con file in memoria al posto di Spatie Media Library. */
class SignatureTestService extends SignatureRequestService
{
    /** @var array<int, string> id documento => PDF originale */
    public array $originals = [];

    /** @var array<int, array{pdf: string, name: string}> id documento => PDF firmato salvato */
    public array $signed = [];

    protected function readOriginalPdf(Document $document): ?string
    {
        return $this->originals[$document->id] ?? null;
    }

    protected function storeSigned(Document $document, string $pdf, string $fileName): void
    {
        $this->signed[$document->id] = ['pdf' => $pdf, 'name' => $fileName];
    }
}
