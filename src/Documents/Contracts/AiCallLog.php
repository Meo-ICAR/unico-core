<?php

namespace Unico\Core\Documents\Contracts;

use Unico\Core\Models\Document;

/**
 * Registro delle chiamate AI andate male: l'app lega il proprio (es. la tabella delle chiamate ai web service) per capire la causa.
 * Senza legame vale `NullAiCallLog`: l'errore resta solo nell'analisi del documento.
 */
interface AiCallLog
{
    /** Registra la chiamata fallita e ne ritorna il riferimento (id), se il registro lo prevede. */
    public function failed(Document $document, string $operation, string $error): ?string;

    /** Indirizzo della pagina che mostra la chiamata registrata. */
    public function url(string $reference): ?string;
}
