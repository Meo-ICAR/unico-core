<?php

namespace Unico\Core\Documents\Api;

/** Esito di una richiesta API: con `content` il documento riceve il file; senza (o se simulata) resta da caricare. */
final class DocumentApiResult
{
    /**
     * @param  array<string, mixed>  $meta  dati utili da conservare sul documento (mai credenziali)
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly bool $simulated = false,
        public readonly ?string $content = null,
        public readonly ?string $fileName = null,
        public readonly array $meta = [],
    ) {}

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }

    public function hasFile(): bool
    {
        return $this->ok && ! $this->simulated && $this->content !== null && $this->fileName !== null;
    }
}
