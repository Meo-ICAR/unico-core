<?php

namespace Unico\Core\Documents\Api;

use Unico\Core\Models\Document;

/** Un servizio API che produce il contenuto di un documento (es. i dati societari per la visura). */
interface DocumentApiFetcher
{
    /** Chiave salvata in `document_types.api_service`. */
    public function key(): string;

    public function label(): string;

    /** Chiama il servizio per il proprietario del documento. */
    public function fetch(Document $document): DocumentApiResult;
}
