<?php

namespace Unico\Core\Enums;

/** Anomalie tecniche rilevate al caricamento di un documento (verifica senza AI). */
enum DocumentAnomaly: string
{
    case MissingFile = 'missing_file';
    case Unreadable = 'unreadable';
    case Duplicate = 'duplicate';
    case Expired = 'expired';
    case SignatureMissing = 'signature_missing';
    case SignatureInvalid = 'signature_invalid';

    public function label(): string
    {
        return match ($this) {
            self::MissingFile => 'File mancante',
            self::Unreadable => 'File vuoto, danneggiato o non apribile',
            self::Duplicate => 'File già caricato per questo soggetto e questo tipo',
            self::Expired => 'Documento scaduto',
            self::SignatureMissing => 'Firma mancante o in attesa',
            self::SignatureInvalid => 'Firma non completata da tutti i firmatari',
        };
    }
}
