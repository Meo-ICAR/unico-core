<?php

namespace Unico\Core\Documents\Api;

use Unico\Core\Models\Document;

/** Codice fiscale o partita IVA e indirizzo del proprietario di un documento (cliente, azienda, produttore, pratica). */
trait ResolvesDocumentSubject
{
    protected function taxCodeOf(Document $document): string
    {
        $owner = $document->documentable;

        foreach (['vat_number', 'piva', 'vat', 'tax_code', 'codice_fiscale'] as $attribute) {
            $value = $owner?->getAttribute($attribute);

            if (filled($value)) {
                return strtoupper(preg_replace('/\s+/', '', (string) $value) ?? '');
            }
        }

        return '';
    }
}
