<?php

namespace Unico\Core\Documents;

use Illuminate\Contracts\Container\Container;
use Unico\Core\Documents\Contracts\DocumentCheck;

/**
 * I controlli disponibili, per codice. L'elenco base sta in `unico-core.documents.checks`;
 * un'app aggiunge i propri con `extend()` o nella configurazione.
 */
class CheckRegistry
{
    /** @var array<string, DocumentCheck|class-string<DocumentCheck>> */
    private array $checks;

    public function __construct(private Container $container, ?array $checks = null)
    {
        $this->checks = $checks ?? (array) config('unico-core.documents.checks', []);
    }

    public function extend(string $name, DocumentCheck|string $check): void
    {
        $this->checks[$name] = $check;
    }

    public function get(string $name): ?DocumentCheck
    {
        $check = $this->checks[$name] ?? null;

        if (is_string($check)) {
            $check = class_exists($check) ? $this->container->make($check) : null;
        }

        return $check instanceof DocumentCheck ? $check : null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->checks);
    }
}
