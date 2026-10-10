<?php

namespace Unico\Core\Mail;

/**
 * Estrae le informazioni utili da un messaggio di mancato recapito (bounce).
 *
 * Prova prima a leggere il blocco DSN standard (RFC 3464,
 * Content-Type: message/delivery-status); se assente, ripiega su una
 * ricerca dell'indirizzo email nel corpo del messaggio.
 */
class BounceParser
{
    /**
     * @return array{failed_email:string,status_code:?string,diagnostic_code:?string,bounce_type:string}|null
     */
    public function parse(string $raw): ?array
    {
        $raw = $this->unfold($raw);

        $finalRecipient = $this->matchField($raw, 'Final-Recipient');
        $originalRecipient = $this->matchField($raw, 'Original-Recipient');
        $status = $this->matchField($raw, 'Status');
        $diagnostic = $this->matchField($raw, 'Diagnostic-Code');

        $email = $this->extractEmail($finalRecipient)
            ?? $this->extractEmail($originalRecipient)
            ?? $this->extractEmail($diagnostic)
            ?? $this->firstEmailInBody($raw);

        if ($email === null) {
            return null;
        }

        $statusCode = $status !== null && preg_match('/([245]\.\d{1,3}\.\d{1,3})/', $status, $m)
            ? $m[1]
            : $this->statusFromDiagnostic($diagnostic);

        return [
            'failed_email' => mb_strtolower($email),
            'status_code' => $statusCode,
            'diagnostic_code' => $diagnostic !== null ? mb_substr($diagnostic, 0, 255) : null,
            'bounce_type' => $this->classify($statusCode, $diagnostic),
        ];
    }

    private function classify(?string $statusCode, ?string $diagnostic): string
    {
        $probe = $statusCode ?? $diagnostic ?? '';

        if (preg_match('/(^|[^0-9])5\.\d/', $probe) || preg_match('/\b55\d\b/', $probe)) {
            return 'hard';
        }

        if (preg_match('/(^|[^0-9])4\.\d/', $probe) || preg_match('/\b45\d\b/', $probe)) {
            return 'soft';
        }

        return 'unknown';
    }

    private function statusFromDiagnostic(?string $diagnostic): ?string
    {
        if ($diagnostic !== null && preg_match('/\b([245]\.\d{1,3}\.\d{1,3})\b/', $diagnostic, $m)) {
            return $m[1];
        }

        return null;
    }

    private function matchField(string $raw, string $field): ?string
    {
        if (preg_match('/^'.preg_quote($field, '/').':\s*(.+)$/im', $raw, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractEmail(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // "rfc822; user@example.com" oppure "<user@example.com>"
        if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $value, $m)) {
            return $m[0];
        }

        return null;
    }

    private function firstEmailInBody(string $raw): ?string
    {
        if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $raw, $m)) {
            return $m[0];
        }

        return null;
    }

    /**
     * Ricompone gli header spezzati su più righe (folding RFC 5322).
     */
    private function unfold(string $raw): string
    {
        return preg_replace("/\r?\n[ \t]+/", ' ', $raw) ?? $raw;
    }
}
