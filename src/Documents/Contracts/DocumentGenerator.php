<?php

namespace Unico\Core\Documents\Contracts;

use Illuminate\Database\Eloquent\Model;
use Unico\Core\Documents\GeneratedDocument;

/**
 * Produce un documento da un template (nomine, DPA, schede...) per un soggetto.
 * Le implementazioni restano nelle app (es. unicogdpr: Blade → PDF); il pacchetto conosce solo il contratto.
 */
interface DocumentGenerator
{
    /** @return array<int, string> codici dei template disponibili */
    public function templates(): array;

    public function supports(string $template, Model $subject): bool;

    /**
     * @param  array<string,mixed>  $options
     *
     * @throws \InvalidArgumentException se il template o il soggetto non sono supportati
     */
    public function generate(string $template, Model $subject, array $options = []): GeneratedDocument;
}
