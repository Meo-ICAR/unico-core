<?php

namespace Unico\Core\Documents;

/** Il file di un documento, letto dallo storage. */
final class DocumentFile
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
        public readonly string $name,
    ) {}

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf' || str_ends_with(strtolower($this->name), '.pdf');
    }
}
