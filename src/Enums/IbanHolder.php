<?php

namespace Unico\Core\Enums;

use Filament\Support\Contracts\HasLabel;

/** Intestatario del conto corrente su cui si paga (intervista preliminare). */
enum IbanHolder: string implements HasLabel
{
    case Richiedente = 'richiedente';
    case Coobbligato = 'coobbligato';

    public function getLabel(): string
    {
        return match ($this) {
            self::Richiedente => 'Richiedente',
            self::Coobbligato => 'Coobbligato',
        };
    }
}
