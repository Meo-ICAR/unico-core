<?php

namespace Unico\Core\Signature;

class PhoneNumber
{
    /**
     * Normalizza un numero in formato E.164; null se vuoto o non numerico.
     */
    public static function toE164(?string $raw, string $defaultPrefix = '+39'): ?string
    {
        $number = preg_replace('/[\s\-().]/', '', (string) $raw);

        if ($number === '' || ! preg_match('/^\+?\d+$/', $number)) {
            return null;
        }

        if (str_starts_with($number, '+')) {
            return $number;
        }

        if (str_starts_with($number, '00')) {
            return '+'.substr($number, 2);
        }

        return $defaultPrefix.$number;
    }

    /** Solo cifre, senza prefisso internazionale italiano (+39 / 0039): per confrontare numeri scritti in modi diversi. */
    public static function national(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $digits = preg_replace('/^00/', '', $digits);

        return strlen($digits) >= 11 && str_starts_with($digits, '39') ? substr($digits, 2) : $digits;
    }

    /** I due numeri coincidono in forma nazionale (un numero vuoto non coincide con nulla). */
    public static function same(string $a, string $b): bool
    {
        $a = self::national($a);

        return $a !== '' && $a === self::national($b);
    }
}
