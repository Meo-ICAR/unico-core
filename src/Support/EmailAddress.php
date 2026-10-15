<?php

namespace Unico\Core\Support;

/** Controllo e pulizia degli indirizzi email scritti a mano: dice anche cosa non va, in italiano. */
final class EmailAddress
{
    private const MAX_LENGTH = 254;

    private const MAX_LOCAL = 64;

    /** Refusi comuni nei domini più usati: dominio scritto male => dominio giusto. */
    private const TYPOS = [
        'gmial.com' => 'gmail.com', 'gmal.com' => 'gmail.com', 'gnail.com' => 'gmail.com', 'gmail.con' => 'gmail.com', 'gmail.co' => 'gmail.com', 'gmaill.com' => 'gmail.com',
        'hotmial.com' => 'hotmail.com', 'hotmal.com' => 'hotmail.com', 'hotmail.con' => 'hotmail.com', 'hotmial.it' => 'hotmail.it', 'hotmail.ot' => 'hotmail.it',
        'outlok.com' => 'outlook.com', 'outlook.con' => 'outlook.com', 'outlook.ot' => 'outlook.it',
        'yaho.com' => 'yahoo.com', 'yahoo.con' => 'yahoo.com', 'yahoo.ot' => 'yahoo.it', 'liberio.it' => 'libero.it', 'libero.ot' => 'libero.it',
    ];

    /** Spazi attorno, prefisso «mailto:» e maiuscole tolti. */
    public static function normalize(string $email): string
    {
        $email = trim($email);
        $email = preg_replace('/^mailto:/i', '', $email);

        return mb_strtolower(trim($email));
    }

    /** Il messaggio che spiega cosa non va, oppure null se l'indirizzo è valido. */
    public static function check(string $email, bool $checkDns = false): ?string
    {
        $email = self::normalize($email);

        if ($email === '') {
            return 'Scrivi un indirizzo email.';
        }
        if (strlen($email) > self::MAX_LENGTH) {
            return 'L\'indirizzo email è troppo lungo.';
        }
        if (substr_count($email, '@') !== 1) {
            return 'Nell\'indirizzo email deve esserci una sola chiocciola (@).';
        }

        [$local, $domain] = explode('@', $email);

        if ($local === '' || strlen($local) > self::MAX_LOCAL || str_starts_with($local, '.') || str_ends_with($local, '.') || str_contains($local, '..')
            || ! preg_match('/^[a-z0-9!#$%&\'*+\/=?^_`{|}~.-]+$/', $local)) {
            return 'La parte prima della chiocciola non è valida.';
        }
        if ($domain === '') {
            return 'Manca il dominio dopo la chiocciola.';
        }
        if (! str_contains($domain, '.')) {
            return 'Manca il suffisso del dominio (per esempio .it o .com).';
        }
        if (str_starts_with($domain, '.') || str_ends_with($domain, '.') || str_contains($domain, '..')) {
            return 'Il dominio non è valido.';
        }

        $labels = explode('.', $domain);
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63 || ! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
                return 'Il dominio non è valido.';
            }
        }
        if (! preg_match('/^(xn--[a-z0-9-]+|[a-z]{2,})$/', end($labels))) {
            return 'Il suffisso del dominio non è valido.';
        }

        if ($checkDns && ! (checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A'))) {
            return 'Il dominio non risulta raggiungibile.';
        }

        return null;
    }

    public static function isValid(string $email, bool $checkDns = false): bool
    {
        return self::check($email, $checkDns) === null;
    }

    /** L'indirizzo corretto se il dominio sembra un refuso comune (gmial.com), altrimenti null. */
    public static function suggest(string $email): ?string
    {
        $email = self::normalize($email);
        if (substr_count($email, '@') !== 1) {
            return null;
        }

        [$local, $domain] = explode('@', $email);

        return isset(self::TYPOS[$domain]) ? $local.'@'.self::TYPOS[$domain] : null;
    }
}
