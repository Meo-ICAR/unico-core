<?php

namespace Unico\Core\Documents;

use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentAnalysis;

/** Esito complessivo della pipeline: il peggiore fra i passi (failed > needs_review > passed). */
final class PipelineResult
{
    /**
     * @param  list<DocumentAnalysis>  $analyses
     * @param  list<string>  $issues
     * @param  array<string,string>  $proposals  dati letti da far confermare (solo se i controlli sono superati)
     */
    public function __construct(
        public readonly Document $document,
        public readonly string $outcome,
        public readonly array $analyses = [],
        public readonly array $issues = [],
        public readonly array $proposals = [],
    ) {}

    public function passed(): bool
    {
        return $this->outcome === 'passed';
    }
}
