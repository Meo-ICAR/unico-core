<?php

namespace Unico\Core\Documents\Contracts;

use Unico\Core\Models\Document;

/** I dati già noti sul soggetto del documento, con cui confrontare quelli letti. */
interface DeclaredData
{
    /**
     * Chiavi usate dai controlli: cognome, nome, codice_fiscale, data_nascita, documento_numero, documento_scadenza.
     *
     * @return array<string,mixed>
     */
    public function for(Document $document): array;
}
