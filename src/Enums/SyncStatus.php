<?php

namespace Unico\Core\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/** Stato di sincronizzazione di un documento con lo storage in cloud (Drive o SharePoint): valori di documents.sync_status. */
enum SyncStatus: string implements HasColor, HasIcon, HasLabel
{
    case LOCAL = 'local';
    case SYNCING = 'syncing';
    case SYNCED = 'synced';
    case FAILED = 'failed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::LOCAL => 'Solo locale',
            self::SYNCING => 'In sincronizzazione',
            self::SYNCED => 'Sincronizzato',
            self::FAILED => 'Errore di sincronizzazione',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::LOCAL => 'gray',
            self::SYNCING => 'info',
            self::SYNCED => 'success',
            self::FAILED => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::LOCAL => 'heroicon-m-server',
            self::SYNCING => 'heroicon-m-arrow-path',
            self::SYNCED => 'heroicon-m-check-circle',
            self::FAILED => 'heroicon-m-x-circle',
        };
    }
}
