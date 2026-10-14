<?php

namespace Unico\Core\Documents\Contracts;

use Unico\Core\Documents\DocumentFile;
use Unico\Core\Models\Document;

/** Dove si prende il file di un documento (default: la media library). */
interface DocumentFileSource
{
    /** @return DocumentFile|null null se il documento non ha alcun file registrato; un file registrato ma sparito torna con contenuto vuoto */
    public function fetch(Document $document): ?DocumentFile;
}
