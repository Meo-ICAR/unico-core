<?php

namespace Unico\Core\Enums;

use Filament\Support\Contracts\HasLabel;

enum SignerRole: string implements HasLabel
{
    case Client = 'client';
    case Fornitore = 'fornitore';

    /** Il ruolo di uno slot di firma: i moduli importati da unicoloan scrivono «collaborator» per il collaboratore. */
    public static function fromSlot(string $role): self
    {
        return $role === 'collaborator' ? self::Fornitore : (self::tryFrom($role) ?? self::Client);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Client => 'Cliente',
            self::Fornitore => 'Fornitore',
        };
    }
}
