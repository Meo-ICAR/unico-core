<?php

namespace Unico\Core\Pdf;

/**
 * Valori grezzi per compilare un modulo, indicizzati per chiave (`pratica.amount`, `client.name`...).
 * Le chiavi disponibili e il modo di risolverle sono dell'app; qui sono semplici stringhe.
 * Un valore è «assente» se è null o stringa vuota; false e 0 sono valori validi.
 */
final class ResolvedModuleData
{
    /** @param  array<string, mixed>  $values */
    public function __construct(public readonly array $values) {}

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function has(string $key): bool
    {
        $value = $this->get($key);

        return $value !== null && $value !== '';
    }

    public function isTruthy(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /** Confronto di stringa con il valore (enum → valore, null → ''). */
    public function equals(string $key, string $value): bool
    {
        $actual = $this->get($key);

        if ($actual instanceof \BackedEnum) {
            $actual = $actual->value;
        }

        return (string) $actual === $value;
    }

    /**
     * @param  iterable<string>  $keys
     * @return array<int, string>
     */
    public function missingAmong(iterable $keys): array
    {
        $missing = [];

        foreach ($keys as $key) {
            if (! $this->has($key) && ! in_array($key, $missing, true)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }
}
