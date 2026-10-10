<?php

namespace Unico\Core\Documents;

/** Documento prodotto da un template (di norma un PDF), pronto da allegare a un `Document`. */
final class GeneratedDocument
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $filename,
        public readonly string $mime = 'application/pdf',
    ) {}
}
