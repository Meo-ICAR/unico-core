<?php

namespace Unico\Core\Documents;

use Unico\Core\Documents\Contracts\AiCallLog;
use Unico\Core\Models\Document;

class NullAiCallLog implements AiCallLog
{
    public function failed(Document $document, string $operation, string $error): ?string
    {
        return null;
    }

    public function url(string $reference): ?string
    {
        return null;
    }
}
