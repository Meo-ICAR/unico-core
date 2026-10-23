<?php

namespace Unico\Core\Enums;

use Filament\Support\Contracts\HasLabel;

/** Stato civile (intervista preliminare). */
enum MaritalStatus: string implements HasLabel
{
    case CelibeNubile = 'celibe_nubile';
    case ConiugatoComunione = 'coniugato_comunione';
    case ConiugatoSeparazione = 'coniugato_separazione';
    case Convivente = 'convivente';
    case Separato = 'separato';
    case Divorziato = 'divorziato';
    case Vedovo = 'vedovo';

    public function getLabel(): string
    {
        return match ($this) {
            self::CelibeNubile => 'Celibe/Nubile',
            self::ConiugatoComunione => 'Coniugato in comunione di beni',
            self::ConiugatoSeparazione => 'Coniugato in separazione di beni',
            self::Convivente => 'Convivente',
            self::Separato => 'Separato/a',
            self::Divorziato => 'Divorziato/a',
            self::Vedovo => 'Vedovo/a',
        };
    }
}
