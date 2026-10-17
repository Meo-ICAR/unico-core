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
    case MISSING_DATA = 'dati_mancanti';
    case PRINT = 'stampare';
    case DOWNLOAD = 'scaricare';
    case TO_SEND_OTP = 'da_inviare_otp';
    case SENT_OTP = 'inviato_otp';
    case SIGNED_OTP = 'firmato_otp';
    case TO_APPROVE_OTP = 'da_approvare_otp';

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
            self::MISSING_DATA => 'Dati obbligatori mancanti per la stampa',
            self::PRINT => 'Da stampare',
            self::DOWNLOAD => 'Da scaricare',
            self::TO_SEND_OTP => 'Da inviare (OTP)',
            self::SENT_OTP => 'Inviato (OTP)',
            self::SIGNED_OTP => 'Firmato (OTP)',
            self::TO_APPROVE_OTP => 'Da approvare (OTP)',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::NA, self::REVOKED => 'gray',
            self::PENDING, self::SUPPLEMENTARY, self::PRINT, self::DOWNLOAD, self::TO_SEND_OTP => 'info',
            self::UPLOADED, self::PROVISIONAL, self::ANOMALY, self::MISSING_DATA, self::SENT_OTP, self::TO_APPROVE_OTP => 'warning',
            self::APPROVED, self::SIGNED_OTP => 'success',
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
            self::MISSING_DATA => 'heroicon-m-pencil-square',
            self::PRINT => 'heroicon-m-printer',
            self::DOWNLOAD => 'heroicon-m-arrow-down-tray',
            self::TO_SEND_OTP => 'heroicon-m-paper-airplane',
            self::SENT_OTP => 'heroicon-m-envelope',
            self::SIGNED_OTP => 'heroicon-m-pencil-square',
            self::TO_APPROVE_OTP => 'heroicon-m-clock',
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

    /** Documento che soddisfa la richiesta: caricato o provvisorio, già OK, firmato, o non applicabile. */
    public function soddisfa(): bool
    {
        return in_array($this, [self::UPLOADED, self::PROVISIONAL, self::APPROVED, self::NA, self::SIGNED_OTP, self::TO_APPROVE_OTP], true);
    }

    /**
     * Fasi del documento da firmare, nell'ordine: compilare i dati mancanti, stampare o scaricare il modulo, inviarlo per la firma OTP, firmarlo,
     * farlo approvare, caricarlo. Una fase successiva non torna mai indietro.
     *
     * @return array<int, self>
     */
    public static function firma(): array
    {
        return [self::MISSING_DATA, self::PRINT, self::DOWNLOAD, self::TO_SEND_OTP, self::SENT_OTP, self::SIGNED_OTP, self::TO_APPROVE_OTP, self::UPLOADED];
    }

    /** Il modulo non è ancora partito per la firma: si deve stampare o scaricare. */
    public function isBeforeSending(): bool
    {
        return in_array($this, [self::PENDING, self::MISSING_DATA, self::PRINT, self::DOWNLOAD], true);
    }

    /** La firma OTP è stata fatta (con o senza approvazione ancora in attesa). */
    public function isSigned(): bool
    {
        return in_array($this, [self::SIGNED_OTP, self::TO_APPROVE_OTP], true);
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
