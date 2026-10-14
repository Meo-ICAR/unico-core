<?php

namespace Unico\Core\Documents;

use Closure;
use Unico\Core\Models\Document;

/** Ciò che serve a un controllo: il documento, i dati letti (una sola lettura AI, in modo pigro) e quelli già noti. */
final class DocumentContext
{
    /** @var array<string,mixed>|null */
    private ?array $fields = null;

    private bool $loaded = false;

    /**
     * @param  Closure():(array<string,mixed>|null)  $reader  esegue la lettura al primo uso
     * @param  array<string,mixed>  $declared  dati già noti sul soggetto (cognome, nome, codice_fiscale, data_nascita...)
     * @param  array<string,mixed>  $params  parametri del singolo controllo
     */
    public function __construct(
        public readonly Document $document,
        public readonly string $kind,
        private readonly Closure $reader,
        public readonly array $declared = [],
        public readonly array $params = [],
    ) {}

    /** @return array<string,mixed>|null null se il documento non è stato letto */
    public function fields(): ?array
    {
        if (! $this->loaded) {
            $this->fields = ($this->reader)();
            $this->loaded = true;
        }

        return $this->fields;
    }

    public function withParams(array $params): self
    {
        $copy = new self($this->document, $this->kind, fn () => $this->fields(), $this->declared, $params);

        return $copy;
    }

    public function label(): string
    {
        return $this->document->documentType?->name ?? $this->kind;
    }
}
