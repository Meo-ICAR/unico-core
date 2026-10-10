<?php

namespace Unico\Core\Mail\Contracts;

use Illuminate\Support\Collection;

/**
 * Cerca referenti per email/telefono su dati che il pacchetto non conosce (es. le richieste degli interessati di unicogdpr).
 * Si registra con `$this->app->tag(MiaClasse::class, 'unico-core.sender-matchers')`.
 */
interface SenderMatcher
{
    /** @return Collection<int, array{type: string, label: string, matched_on: string, model: mixed}> */
    public function match(?string $email, ?string $phone, int|string|null $companyId): Collection;
}
