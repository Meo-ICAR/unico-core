<?php

namespace Unico\Core\Signature;

/**
 * Modalità simulazione della firma OTP, per le dimostrazioni: con il provider `fake` nessun SMS parte e il «cliente» firma
 * inserendo un codice fisso. Si attiva con `SIGNATURE_DRIVER=fake` e `SIGNATURE_DEMO=true`; mai con il provider reale.
 */
class SignatureDemo
{
    public static function attiva(): bool
    {
        return config('unico-core.signature.driver') === 'fake' && (bool) config('unico-core.signature.demo', false);
    }

    /** Codice OTP che il cliente deve digitare per firmare nella simulazione. */
    public static function codice(): string
    {
        return (string) (config('unico-core.signature.demo_otp') ?: '123456');
    }
}
