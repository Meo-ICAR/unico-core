<?php

namespace Unico\Core\Tests\Support;

use Illuminate\Support\Collection;
use Unico\Core\Documents\Contracts\DocumentReader;

/** Lettore AI finto: risposte decise dal test, nessuna rete. */
class FakeReader implements DocumentReader
{
    public bool $enabled = true;

    public ?array $fields = null;

    public ?array $classification = null;

    public int $reads = 0;

    public int $classifications = 0;

    public bool $throws = false;

    public ?array $usage = ['model' => 'fake-model', 'input_tokens' => 1000, 'output_tokens' => 200];

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function read(string $kind, string $mime, string $bytes): ?array
    {
        $this->reads++;

        if ($this->throws) {
            throw new \RuntimeException('boom con dati personali');
        }

        return $this->fields;
    }

    public function classify(Collection $candidates, string $mime, string $bytes): ?array
    {
        $this->classifications++;

        return $this->classification;
    }

    public function lastUsage(): ?array
    {
        return $this->usage;
    }
}
