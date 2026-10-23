<?php

namespace Unico\Core\Enums;

use Filament\Support\Contracts\HasLabel;

/** Grado di parentela del coobbligato con il richiedente (intervista preliminare). */
enum Kinship: string implements HasLabel
{
    case Coniuge = 'coniuge';
    case Convivente = 'convivente';
    case Genitore = 'genitore';
    case Figlio = 'figlio';
    case AltraParentela = 'altra_parentela';
    case Altro = 'altro';

    public function getLabel(): string
    {
        return match ($this) {
            self::Coniuge => 'Coniuge',
            self::Convivente => 'Convivente',
            self::Genitore => 'Genitore',
            self::Figlio => 'Figlio/a',
            self::AltraParentela => 'Altra parentela',
            self::Altro => 'Altro',
        };
    }
}
