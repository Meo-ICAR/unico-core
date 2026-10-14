<?php

namespace Unico\Core\Documents\Contracts;

use Unico\Core\Models\Document;

/** Decide se un documento può essere inviato all'AI (es. solo dopo l'informativa privacy verificata). */
interface AnalysisGate
{
    public function allows(Document $document, string $kind): bool;
}
