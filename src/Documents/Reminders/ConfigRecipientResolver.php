<?php

namespace Unico\Core\Documents\Reminders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Unico\Core\Documents\Reminders\Contracts\RecipientResolver;
use Unico\Core\Models\Document;
use Unico\Core\Models\User;

/**
 * Destinatari decisi dalla configurazione `unico-core.documents.reminders.recipients`, per alias del soggetto:
 * `name` e `email` sono elenchi di campi provati in ordine (il primo valorizzato vince);
 * un campo scritto `a+b` unisce i due valori (es. `name+first_name`). Un'app con regole proprie
 * (es. i documenti di una pratica vanno al cliente) lega una propria implementazione.
 */
class ConfigRecipientResolver implements RecipientResolver
{
    public function resolve(Model $documentable): array
    {
        $alias = $documentable->getMorphClass();
        $rule = (array) config("unico-core.documents.reminders.recipients.{$alias}", []);

        $name = $this->first($documentable, (array) ($rule['name'] ?? []));

        return [
            'name' => $name ?? $this->modelLabel($documentable),
            'email' => $this->first($documentable, (array) ($rule['email'] ?? [])),
        ];
    }

    public function resolveForDocument(Document $document): array
    {
        $documentable = $document->documentable;

        if ($documentable === null) {
            return ['name' => 'Destinatario non disponibile', 'email' => $this->fallbackEmail()];
        }

        $recipient = $this->resolve($documentable);

        if (blank($recipient['email'])) {
            $recipient['email'] = $this->fallbackEmail();
        }

        return $recipient;
    }

    public function entityName(Document $document): string
    {
        $documentable = $document->documentable;

        return $documentable === null ? '-' : $this->resolve($documentable)['name'];
    }

    public function typeLabel(?string $alias): string
    {
        if ($alias === null) {
            return 'Sconosciuto';
        }

        return config("unico-core.documents.reminders.recipients.{$alias}.label") ?? Str::headline($alias);
    }

    private function first(Model $model, array $fields): ?string
    {
        foreach ($fields as $field) {
            $value = trim(collect(explode('+', $field))->map(fn (string $f) => (string) $model->getAttribute($f))->implode(' '));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function modelLabel(Model $model): string
    {
        return $this->first($model, ['name', 'title', 'protocol_number']) ?? $this->typeLabel($model->getMorphClass());
    }

    private function fallbackEmail(): ?string
    {
        $configured = config('unico-core.documents.reminders.fallback_email');

        if (filled($configured)) {
            return $configured;
        }

        return (config('unico-core.user_model') ?? User::class)::query()->orderBy('id')->value('email');
    }
}
