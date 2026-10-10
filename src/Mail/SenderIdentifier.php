<?php

namespace Unico\Core\Mail;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Unico\Core\Mail\Contracts\SenderMatcher;
use Unico\Core\Models;

/**
 * Riconosce chi ha scritto (o telefonato) a partire da email e/o telefono, sui dati del pacchetto:
 * dipendenti, clienti, banche/mandanti, fornitori, utenti e registro reclami. Le app aggiungono i propri
 * referenti con i `SenderMatcher`. Niente tabelle o relazioni nuove: si leggono i campi esistenti.
 *
 * Alcuni campi (pec, dpo_email...) contengono più indirizzi separati da "/", "," o testo libero: si estraggono uno
 * a uno, per non avere falsi positivi da un semplice LIKE.
 */
class SenderIdentifier
{
    private const EMAIL_PATTERN = '/[A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+/';

    /** @var array<int, array{model: class-string<Model>, label: array<int,string>, emails: array<int,string>, phones: array<int,string>}> */
    private const SOURCES = [
        ['model' => Models\Employee::class, 'label' => ['name'], 'emails' => ['email', 'pec'], 'phones' => ['phone']],
        ['model' => Models\Client::class, 'label' => ['name'], 'emails' => ['email', 'pec', 'privacy_contact_email', 'dpo_email'], 'phones' => ['phone']],
        ['model' => Models\Cliente::class, 'label' => ['name'], 'emails' => ['email', 'privacy_contact_email', 'dpo_email'], 'phones' => []],
        ['model' => Models\Fornitore::class, 'label' => ['name'], 'emails' => ['email', 'pec', 'email_private'], 'phones' => ['tel']],
        ['model' => Models\User::class, 'label' => ['name'], 'emails' => ['email'], 'phones' => []],
        ['model' => Models\ComplaintRegistry::class, 'label' => ['complainant_name', 'protocol_number'], 'emails' => ['complainant_email', 'receiving_email'], 'phones' => ['complainant_phone']],
    ];

    /** @param  iterable<SenderMatcher>  $matchers */
    public function __construct(private iterable $matchers = []) {}

    /** @return Collection<int, array{type: string, label: string, matched_on: string, model: mixed}> */
    public function identify(?string $email = null, ?string $phone = null, int|string|null $companyId = null): Collection
    {
        $email = filled($email) ? mb_strtolower(trim($email)) : null;
        $phone = filled($phone) ? preg_replace('/\D+/', '', $phone) : null;

        if (blank($email) && blank($phone)) {
            return collect();
        }

        $found = collect();
        foreach (self::SOURCES as $source) {
            $found = $found->merge($this->matchSource($source, $email, $phone, $companyId));
        }
        foreach ($this->matchers as $matcher) {
            $found = $found->merge($matcher->match($email, $phone, $companyId));
        }

        return $found->values();
    }

    /** Tutti gli indirizzi presenti in un campo, anche se ce n'è più d'uno. @return array<int, string> */
    public static function extractEmails(?string $field): array
    {
        if (blank($field)) {
            return [];
        }

        preg_match_all(self::EMAIL_PATTERN, $field, $found);

        return array_map('mb_strtolower', $found[0] ?? []);
    }

    private function matchSource(array $source, ?string $email, ?string $phone, int|string|null $companyId): Collection
    {
        /** @var Model $prototype */
        $prototype = new $source['model'];

        $query = $source['model']::query()
            ->when($companyId, fn (Builder $q) => $q->where($prototype->qualifyColumn('company_id'), $companyId))
            ->where(function (Builder $q) use ($source, $email, $phone) {
                if ($email) {
                    foreach ($source['emails'] as $column) {
                        $q->orWhere($column, 'like', "%{$email}%");
                    }
                }
                if ($phone) {
                    foreach ($source['phones'] as $column) {
                        $q->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '-', ''), '+', ''), '/', '') LIKE ?", ["%{$phone}"]);
                    }
                }
            });

        return $query->get()
            ->map(function (Model $model) use ($source, $email, $phone) {
                $matchedOn = $this->matchedColumn($model, $source, $email, $phone);

                return $matchedOn ? [
                    'type' => Relation::getMorphAlias($model::class),
                    'label' => $this->label($model, $source['label']),
                    'matched_on' => $matchedOn,
                    'model' => $model,
                ] : null;
            })
            ->filter();
    }

    private function matchedColumn(Model $model, array $source, ?string $email, ?string $phone): ?string
    {
        if ($email) {
            foreach ($source['emails'] as $column) {
                if (in_array($email, self::extractEmails($model->{$column}), true)) {
                    return $column;
                }
            }
        }

        if ($phone) {
            foreach ($source['phones'] as $column) {
                $digits = preg_replace('/\D+/', '', (string) $model->{$column});
                if ($digits !== '' && str_ends_with($digits, $phone)) {
                    return $column;
                }
            }
        }

        return null;
    }

    private function label(Model $model, array $columns): string
    {
        foreach ($columns as $column) {
            if (filled($model->{$column})) {
                return (string) $model->{$column};
            }
        }

        return class_basename($model).' #'.$model->getKey();
    }
}
