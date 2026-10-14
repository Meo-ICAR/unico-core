<?php

namespace Unico\Core\Documents;

use Illuminate\Support\Carbon;
use Throwable;
use Unico\Core\Models\Client;
use Unico\Core\Models\Document;
use Unico\Core\Models\Pratica;
use Unico\Core\Models\PraticaClient;
use Unico\Core\Pdf\PracticeModuleData;

/**
 * Porta nel database i dati letti dai documenti d'identità e dal codice fiscale: anagrafica del richiedente
 * (creata se manca, collegata alla pratica) ed estremi del documento. Completa solo ciò che è vuoto:
 * non sovrascrive mai dati già inseriti o corretti da una persona.
 */
class PracticeClientPopulator
{
    public function __construct(private PracticeModuleData $moduleData) {}

    public function apply(Pratica $pratica, Document $document): ?Client
    {
        $document->loadMissing('documentType');
        $kind = $document->documentType?->ai_kind;

        if (! in_array($kind, ['identita', 'codice_fiscale'], true)) {
            return null;
        }

        $fields = $document->analyses()->where('kind', 'extraction')->latest('id')->first()?->result['fields'] ?? null;

        if (! is_array($fields)) {
            return null;
        }

        $values = array_filter([
            'name' => $this->text($fields['surname'] ?? null),
            'first_name' => $this->text($fields['name'] ?? null),
            'tax_code' => ($code = $this->text($fields['fiscal_code'] ?? null)) === null ? null : strtoupper(str_replace(' ', '', $code)),
            'birth_date' => $this->date($fields['birth_date'] ?? null)?->toDateString(),
            'birth_place' => $this->text($fields['birth_place'] ?? null),
        ], fn (mixed $value): bool => $value !== null);

        // Pratica di una società (la P.IVA è nel codice fiscale della pratica): i documenti sono dell'amministratore,
        // che si collega con il suo ruolo e non prende il posto della società come richiedente.
        $company = (bool) preg_match('/^\d{11}$/', (string) $pratica->codice_fiscale);
        $client = $company ? $this->person($pratica, $values) : $this->client($pratica, $values);

        if ($client !== null) {
            $this->fillEmpty($client, $values);
            $company ? $this->linkAs($pratica, $client, 'amministratore') : $this->link($pratica, $client);
        }

        if ($kind === 'identita') {
            $this->identityDetails($document, $fields);
        }

        return $client;
    }

    /** Il richiedente già collegato alla pratica, altrimenti il cliente con lo stesso codice fiscale, altrimenti uno nuovo. */
    private function client(Pratica $pratica, array $values): ?Client
    {
        $client = $this->moduleData->findClient($pratica);

        if ($client === null && isset($values['tax_code'])) {
            $client = Client::query()->where('tax_code', $values['tax_code'])->first();
        }

        if ($client === null && isset($values['tax_code'], $values['name'])) {
            $client = Client::query()->create([
                'company_id' => $pratica->company_id,
                'is_person' => strlen($values['tax_code']) !== 11,
            ] + $values);
        }

        return $client;
    }

    /** La persona fisica con quel codice fiscale, creata se manca. */
    private function person(Pratica $pratica, array $values): ?Client
    {
        if (! isset($values['tax_code'], $values['name'])) {
            return null;
        }

        return Client::query()->firstOrCreate(
            ['tax_code' => $values['tax_code']],
            ['company_id' => $pratica->company_id, 'is_person' => true] + $values,
        );
    }

    private function linkAs(Pratica $pratica, Client $client, string $role): void
    {
        PraticaClient::query()->firstOrCreate(
            ['pratica_id' => $pratica->getKey(), 'client_id' => $client->getKey()],
            ['role' => $role, 'name' => trim(($client->first_name ?? '').' '.($client->name ?? ''))],
        );
    }

    /** @param array<string, mixed> $values */
    private function fillEmpty(Client $client, array $values): void
    {
        $empty = array_filter($values, fn (mixed $value, string $key): bool => blank($client->{$key}), ARRAY_FILTER_USE_BOTH);

        if ($empty !== []) {
            $client->forceFill($empty)->save();
        }
    }

    private function link(Pratica $pratica, Client $client): void
    {
        PraticaClient::query()->firstOrCreate(
            ['pratica_id' => $pratica->getKey(), 'client_id' => $client->getKey()],
            ['role' => 'richiedente', 'name' => trim(($client->first_name ?? '').' '.($client->name ?? ''))],
        );

        $missing = array_filter([
            'cognome_cliente' => $pratica->cognome_cliente ?: $client->name,
            'nome_cliente' => $pratica->nome_cliente ?: $client->first_name,
            'codice_fiscale' => $pratica->codice_fiscale ?: $client->tax_code,
        ], fn (mixed $value, string $key): bool => filled($value) && blank($pratica->{$key}), ARRAY_FILTER_USE_BOTH);

        if ($missing !== []) {
            $pratica->forceFill($missing)->save();
        }
    }

    /** @param array<string, mixed> $fields */
    private function identityDetails(Document $document, array $fields): void
    {
        $details = array_filter([
            'docnumber' => $this->text($fields['document_number'] ?? null),
            'emitted_by' => $this->text($fields['issuer'] ?? null),
            'emitted_at' => $this->date($fields['issue_date'] ?? null)?->toDateString(),
            'expires_at' => $this->date($fields['expiry_date'] ?? null)?->toDateString(),
        ], fn (mixed $value): bool => $value !== null);

        $empty = array_filter($details, fn (mixed $value, string $key): bool => blank($document->{$key}), ARRAY_FILTER_USE_BOTH);

        if ($empty !== []) {
            $document->forceFill($empty)->saveQuietly();
        }
    }

    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    private function date(mixed $value): ?Carbon
    {
        $value = $this->text($value);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::createFromFormat('!d/m/Y', $value) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
