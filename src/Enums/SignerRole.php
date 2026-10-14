<?php

namespace Unico\Core\Enums;

use Filament\Support\Contracts\HasLabel;

enum SignerRole: string implements HasLabel
{
    case Client = 'client';
    case Fornitore = 'fornitore';

    public function getLabel(): string
    {
        return match ($this) {
            self::Client => 'Cliente',
            self::Fornitore => 'Fornitore',
        };
    }
}
