<?php

namespace Unico\Core\Pdf;

use RuntimeException;

class PdfFormException extends RuntimeException
{
    public static function missingTemplate(string $path): self
    {
        return new self("Il modulo PDF non esiste su disco: {$path}");
    }

    public static function pdftkFailed(string $details): self
    {
        return new self('Compilazione PDF non disponibile (pdftk): '.trim($details));
    }
}
