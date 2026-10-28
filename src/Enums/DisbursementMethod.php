<?php

namespace Unico\Core\Enums;

use Filament\Support\Contracts\HasLabel;

/** Modalità di erogazione del finanziamento (come arriva il denaro al cliente). */
enum DisbursementMethod: string implements HasLabel
{
    case Bonifico = 'bonifico';
    case Assegno = 'assegno';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bonifico => 'Bonifico',
            self::Assegno => 'Assegno',
        };
    }
}
