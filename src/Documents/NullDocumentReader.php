<?php

namespace Unico\Core\Documents;

use Illuminate\Support\Collection;
use Unico\Core\Documents\Contracts\DocumentReader;

/** Lettura AI non configurata: i file vengono comunque verificati tecnicamente e archiviati. */
class NullDocumentReader implements DocumentReader
{
    public function enabled(): bool
    {
        return false;
    }

    public function read(string $kind, string $mime, string $bytes): ?array
    {
        return null;
    }

    public function classify(Collection $candidates, string $mime, string $bytes): ?array
    {
        return null;
    }

    public function lastUsage(): ?array
    {
        return null;
    }
}
