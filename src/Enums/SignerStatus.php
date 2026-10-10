<?php

namespace Unico\Core\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SignerStatus: string implements HasColor, HasLabel
{
    case Waiting = 'waiting';
    case Notified = 'notified';
    case Signed = 'signed';
    case Declined = 'declined';

    public function getLabel(): string
    {
        return match ($this) {
            self::Waiting => 'Atteso',
            self::Notified => 'Notificato',
            self::Signed => 'Firmato',
            self::Declined => 'Rifiutato',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Waiting => 'gray',
            self::Notified => 'info',
            self::Signed => 'success',
            self::Declined => 'danger',
        };
    }
}
