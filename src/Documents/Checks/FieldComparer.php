<?php

namespace Unico\Core\Documents\Checks;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Confronti fra dato letto dal documento e dato noto (nessuna AI: regole fisse). Porta di DocumentChecker di unicoagent. */
class FieldComparer
{
    /** @return list<string> */
    public function names(string $label, ?string $onDocument, ?string $declared): array
    {
        if (blank($onDocument) || blank($declared)) {
            return [];
        }

        $a = $this->tokens($onDocument);
        $b = $this->tokens($declared);

        // Un nome contenuto nell'altro (secondo nome, doppio cognome) non è una difformità.
        return (! array_diff($a, $b) || ! array_diff($b, $a)) ? [] : [$this->mismatch($label, $onDocument, $declared)];
    }

    /** @return list<string> */
    public function codes(string $label, ?string $onDocument, ?string $declared): array
    {
        if (blank($onDocument) || blank($declared)) {
            return [];
        }

        return $this->alnum($onDocument) === $this->alnum($declared) ? [] : [$this->mismatch($label, $onDocument, $declared)];
    }

    /** @return list<string> */
    public function dates(string $label, ?string $onDocument, ?string $declared): array
    {
        if (blank($onDocument) || blank($declared)) {
            return [];
        }

        return $this->normalizeDate($onDocument) === $this->normalizeDate($declared) ? [] : [$this->mismatch($label, $onDocument, $declared)];
    }

    public function parseDate(?string $text): ?Carbon
    {
        if (blank($text) || ! preg_match('/(\d{1,2})\D(\d{1,2})\D(\d{4})/', $text, $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
            ? Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->startOfDay()
            : null;
    }

    private function mismatch(string $label, string $onDocument, string $declared): string
    {
        return "{$label}: sul documento «{$onDocument}», dichiarato «{$declared}»";
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        return array_values(array_filter(preg_split('/[^A-Z]+/', strtoupper(Str::ascii($text)))));
    }

    private function alnum(string $text): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($text));
    }

    private function normalizeDate(string $date): string
    {
        return preg_match('/(\d{1,2})\D(\d{1,2})\D(\d{4})/', $date, $m)
            ? sprintf('%02d/%02d/%04d', $m[1], $m[2], $m[3])
            : preg_replace('/\D/', '', $date);
    }
}
