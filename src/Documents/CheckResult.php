<?php

namespace Unico\Core\Documents;

/** Esito di un controllo: problemi in italiano (vuoto = superato) e dati proposti da far confermare. */
final class CheckResult
{
    /**
     * @param  list<string>  $issues
     * @param  array<string,string>  $proposals  chiave del dato noto => valore letto dal documento
     */
    public function __construct(public readonly array $issues = [], public readonly array $proposals = []) {}

    public static function pass(array $proposals = []): self
    {
        return new self([], $proposals);
    }

    public static function fail(string ...$issues): self
    {
        return new self(array_values($issues));
    }

    public function passed(): bool
    {
        return $this->issues === [];
    }
}
