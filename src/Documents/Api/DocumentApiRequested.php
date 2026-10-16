<?php

namespace Unico\Core\Documents\Api;

use Unico\Core\Models\Document;

/** Lanciato a ogni richiesta API per un documento: le app lo usano per registrare chiamata e costo. */
final class DocumentApiRequested
{
    public function __construct(
        public readonly Document $document,
        public readonly string $service,
        public readonly DocumentApiResult $result,
        public readonly float $startedAt,
    ) {}
}
