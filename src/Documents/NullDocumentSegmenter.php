<?php

namespace Unico\Core\Documents;

use Unico\Core\Documents\Contracts\DocumentSegmenter;

class NullDocumentSegmenter implements DocumentSegmenter
{
    public function enabled(): bool
    {
        return false;
    }

    public function segments(string $pdfBytes, int $pages): ?array
    {
        return null;
    }
}
