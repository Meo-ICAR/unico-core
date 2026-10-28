<?php

namespace Unico\Core\Enums;

use Filament\Support\Contracts\HasLabel;

/** Modalità scelta dal cliente per ricevere le comunicazioni periodiche di trasparenza della banca. */
enum PeriodicCommunication: string implements HasLabel
{
    case Elettronica = 'elettronica';
    case Cartacea = 'cartacea';

    public function getLabel(): string
    {
        return match ($this) {
            self::Elettronica => 'Elettronica (email)',
            self::Cartacea => 'Cartacea',
        };
    }
}
