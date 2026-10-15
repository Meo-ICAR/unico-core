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

    /**
     * Il messaggio che spiega cosa non va in un numero di telefono, oppure null se è valido.
     * Italiani: cellulari che iniziano per 3 (9 o 10 cifre) e fissi che iniziano per 0 (6-11 cifre); gli esteri vanno in forma
     * internazionale (+44... o 0044...). Con `$mobileOnly` serve un cellulare (per esempio per l'OTP via SMS), salvo `$allowForeign`.
     */
    public static function check(?string $raw, bool $mobileOnly = false, bool $allowForeign = false, string $defaultPrefix = '+39'): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return 'Scrivi il numero di telefono.';
        }

        $e164 = self::toE164($raw, $defaultPrefix);
        if ($e164 === null) {
            return 'Il numero deve contenere solo cifre (e il prefisso internazionale, per esempio +39).';
        }

        $digits = strlen($e164) - 1;
        if ($digits < 8) {
            return 'Il numero ha troppo poche cifre.';
        }
        if ($digits > 15) {
            return 'Il numero ha troppe cifre.';
        }

        if (! str_starts_with($e164, '+39')) {
            return $mobileOnly && ! $allowForeign ? 'Serve un cellulare italiano (inizia per 3).' : null;
        }

        $national = substr($e164, 3);
        if (preg_match('/^3\d{8,9}$/', $national)) {
            return null;
        }
        if (preg_match('/^0\d{5,10}$/', $national)) {
            return $mobileOnly ? 'Serve un numero di cellulare (inizia per 3), non un fisso.' : null;
        }

        return 'Numero italiano non valido: i cellulari iniziano per 3 e i fissi per 0.';
    }

    public static function isValid(?string $raw, bool $mobileOnly = false, bool $allowForeign = false): bool
    {
        return self::check($raw, $mobileOnly, $allowForeign) === null;
    }

    /** `mobile`, `landline` (italiani) o `other` (esteri); null se il numero non è valido. */
    public static function type(?string $raw, string $defaultPrefix = '+39'): ?string
    {
        if (self::check($raw, false, false, $defaultPrefix) !== null) {
            return null;
        }

        $e164 = (string) self::toE164((string) $raw, $defaultPrefix);
        if (! str_starts_with($e164, '+39')) {
            return 'other';
        }

        return str_starts_with(substr($e164, 3), '3') ? 'mobile' : 'landline';
    }

    /** Un cellulare italiano valido. */
    public static function isMobile(?string $raw): bool
    {
        return self::type($raw) === 'mobile';
    }
}
