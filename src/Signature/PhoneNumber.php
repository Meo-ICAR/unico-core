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
}
