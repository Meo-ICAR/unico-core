<?php

namespace Unico\Core\Documents;

use Unico\Core\Documents\Contracts\DeclaredData;
use Unico\Core\Models\Document;

class NullDeclaredData implements DeclaredData
{
    public function for(Document $document): array
    {
        return [];
    }
}
