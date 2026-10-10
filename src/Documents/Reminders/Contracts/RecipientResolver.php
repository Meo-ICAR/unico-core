<?php

namespace Unico\Core\Documents\Reminders\Contracts;

use Illuminate\Database\Eloquent\Model;
use Unico\Core\Models\Document;

/** A chi si manda il sollecito per i documenti di un soggetto, e come si chiama quel soggetto nelle liste. */
interface RecipientResolver
{
    /** @return array{name: string, email: ?string} */
    public function resolve(Model $documentable): array;

    /** Destinatario di un documento; senza email propria vale l'indirizzo di ripiego. */
    public function resolveForDocument(Document $document): array;

    /** Nome leggibile del soggetto (per lo scadenziario). */
    public function entityName(Document $document): string;

    /** Etichetta del tipo di soggetto, dall'alias (es. `employee` => Dipendente). */
    public function typeLabel(?string $alias): string;
}
