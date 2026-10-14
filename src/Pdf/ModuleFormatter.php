<?php

namespace Unico\Core\Pdf;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;
use Throwable;

/** Come un valore diventa testo nel campo del modulo PDF. */
enum ModuleFormatter: string implements HasLabel
{
    case DateIt = 'date_it';
    case MoneyIt = 'money_it';
    case Upper = 'upper';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::DateIt => 'Data (gg/mm/aaaa)',
            self::MoneyIt => 'Importo (1.234,56)',
            self::Upper => 'MAIUSCOLO',
        };
    }

    /** Stringa vuota = nessun dato (il campo non viene toccato). */
    public static function format(mixed $value, ?self $formatter): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($formatter) {
            self::DateIt => self::toCarbon($value)?->format('d/m/Y') ?? (string) $value,
            self::MoneyIt => is_numeric($value) ? number_format((float) $value, 2, ',', '.') : (string) $value,
            self::Upper => mb_strtoupper((string) $value),
            null => match (true) {
                $value instanceof CarbonInterface => $value->format('d/m/Y'),
                is_bool($value) => $value ? 'Sì' : 'No',
                default => (string) $value,
            },
        };
    }

    private static function toCarbon(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
