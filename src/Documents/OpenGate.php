<?php

namespace Unico\Core\Documents;

use Unico\Core\Documents\Contracts\AnalysisGate;
use Unico\Core\Models\Document;

class OpenGate implements AnalysisGate
{
    public function allows(Document $document, string $kind): bool
    {
        return true;
    }
}
