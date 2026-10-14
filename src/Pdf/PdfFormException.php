<?php

namespace Unico\Core\Pdf;

use RuntimeException;

class PdfFormException extends RuntimeException
{
    public static function missingTemplate(string $path): self
    {
        return new self("Il modulo PDF non esiste su disco: {$path}");
    }

    /** @param  array<int, string>  $missing chiavi obbligatorie non valorizzate */
    public static function notPrintable(array $missing): self
    {
        return new self($missing === []
            ? 'Il documento non è un modulo da firmare stampabile.'
            : 'Campi obbligatori mancanti: '.implode(', ', $missing));
    }

    public static function pdftkFailed(string $details): self
    {
        return new self('Compilazione PDF non disponibile (pdftk): '.trim($details));
    }
}
