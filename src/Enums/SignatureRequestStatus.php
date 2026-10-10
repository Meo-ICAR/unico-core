<?php

namespace Unico\Core\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SignatureRequestStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Signed = 'signed';
    case Declined = 'declined';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'In preparazione',
            self::Sent => 'Inviata',
            self::Signed => 'Firmata',
            self::Declined => 'Rifiutata',
            self::Expired => 'Scaduta',
            self::Cancelled => 'Annullata',
            self::Failed => 'Non riuscita',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Sent => 'info',
            self::Signed => 'success',
            self::Declined, self::Failed => 'danger',
            self::Expired, self::Cancelled => 'warning',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Sent], true);
    }

    public function isFinal(): bool
    {
        return ! $this->isOpen();
    }
}
