<?php

namespace Unico\Core\Documents;

use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentType;

/** Esito del caricamento di un file: il documento creato, il tipo riconosciuto (se c'è) e i problemi dei controlli. */
final class IntakeResult
{
    /** @param list<string> $issues */
    public function __construct(
        public readonly string $fileName,
        public readonly Document $document,
        public readonly ?DocumentType $type,
        public readonly string $outcome,
        public readonly array $issues = [],
    ) {}

    /** Il file è stato riconosciuto come uno dei documenti attesi. */
    public function matched(): bool
    {
        return $this->type !== null;
    }

    /** Riconosciuto e controlli superati. */
    public function passed(): bool
    {
        return $this->matched() && $this->outcome === 'passed';
    }
}
