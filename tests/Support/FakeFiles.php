<?php

namespace Unico\Core\Tests\Support;

use Unico\Core\Documents\Contracts\DocumentFileSource;
use Unico\Core\Documents\DocumentFile;
use Unico\Core\Models\Document;

/** File dei documenti tenuti in memoria, per id. */
class FakeFiles implements DocumentFileSource
{
    /** @var array<int, DocumentFile|null> */
    public array $files = [];

    public function fetch(Document $document): ?DocumentFile
    {
        return $this->files[$document->getKey()] ?? null;
    }
}
