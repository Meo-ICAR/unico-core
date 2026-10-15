<?php

namespace Unico\Core\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Stato di un documento. È l'unica fonte dei valori della colonna documents.status:
 * non vanno usate altre stringhe (`approved`, `expired`, `uploaded`...).
 */
enum DocumentStatus: string implements HasColor, HasIcon, HasLabel
{
    case NA = 'na';
    case PENDING = 'richiesto';
    case UPLOADED = 'caricato';
    case APPROVED = 'approvato';
    case REJECTED = 'respinto';
    case PROVISIONAL = 'provvisorio';
    case EXPIRED = 'scaduto';
    case UNREADABLE = 'illegibile';
    case REVOKED = 'revocato';
    case ANOMALY = 'anomalo';
    case SUPPLEMENTARY = 'integrativo';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::NA => 'N/A',
            self::PENDING => 'Richiesto',
            self::UPLOADED => 'Caricato',
            self::APPROVED => 'OK',
            self::REJECTED => 'Respinto',
            self::PROVISIONAL => 'Provvisorio',
            self::EXPIRED => 'Scaduto',
            self::UNREADABLE => 'Illegibile',
            self::REVOKED => 'Revocato',
            self::ANOMALY => 'Anomalo',
            self::SUPPLEMENTARY => 'Integrativo',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::NA, self::REVOKED => 'gray',
            self::PENDING, self::SUPPLEMENTARY => 'info',
            self::UPLOADED, self::PROVISIONAL, self::ANOMALY => 'warning',
            self::APPROVED => 'success',
            self::REJECTED, self::EXPIRED, self::UNREADABLE => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::NA => 'heroicon-m-minus-circle',
            self::PENDING => 'heroicon-m-question-mark-circle',
            self::UPLOADED => 'heroicon-m-arrow-up-tray',
            self::APPROVED => 'heroicon-m-check-badge',
            self::PROVISIONAL => 'heroicon-m-clock',
            self::EXPIRED, self::ANOMALY => 'heroicon-m-exclamation-triangle',
            self::SUPPLEMENTARY => 'heroicon-m-plus-circle',
            self::REJECTED, self::UNREADABLE, self::REVOKED => 'heroicon-m-x-circle',
        };
    }

    /**
     * Esiti che l'istruttoria assegna a un documento caricato: OK, Respinto, Anomalo, N/A, Integrativo (richiesto in più dalla banca).
     *
     * @return array<string, string>
     */
    public static function istruttoria(): array
    {
        return collect([self::APPROVED, self::REJECTED, self::ANOMALY, self::NA, self::SUPPLEMENTARY])->mapWithKeys(fn (self $s): array => [$s->value => $s->getLabel()])->all();
    }

    /** Documento che soddisfa la richiesta: caricato o provvisorio, già OK, o non applicabile. */
    public function soddisfa(): bool
    {
        return in_array($this, [self::UPLOADED, self::PROVISIONAL, self::APPROVED, self::NA], true);
    }

    /**
     * Stati di un documento che non vale più: nessun sollecito e nessuna scadenza da controllare.
     *
     * @return array<int, self>
     */
    public static function inactive(): array
    {
        return [self::EXPIRED, self::REVOKED, self::REJECTED, self::NA];
    }
}
