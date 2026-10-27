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
    /** `address_types.id` del domicilio. */
    private const DOMICILE_TYPE = 2;

    /** `address_types.id` della residenza. */
    private const RESIDENCE_TYPE = 1;

    public function __construct(private PracticeModuleData $moduleData) {}

    /**
     * Dal documento al richiedente della pratica: il cliente collegato (o con quel codice fiscale, o nuovo) si completa e si collega.
     */
    public function apply(Pratica $pratica, Document $document): ?Client
    {
        $read = $this->read($document);

        if ($read === null) {
            return null;
        }

        [$kind, $fields, $values] = $read;

        // Pratica di una società (la P.IVA è nel codice fiscale della pratica): i documenti sono dell'amministratore,
        // che si collega con il suo ruolo e non prende il posto della società come richiedente.
        $company = (bool) preg_match('/^\d{11}$/', (string) $pratica->codice_fiscale);
        $client = $company ? $this->person($pratica, $values) : $this->client($pratica, $values);

        if ($client !== null) {
            $this->fillEmpty($client, $values);
            $company ? $this->linkAs($pratica, $client, 'amministratore') : $this->link($pratica, $client);
            $this->complete($client, $document, $kind, $fields, $company);
        } else {
            $this->complete(null, $document, $kind, $fields, $company);
        }

        return $client;
    }

    /**
     * Dal documento a un cliente già scelto (un garante, un cointestatario): lo completa solo dove è vuoto, senza collegarlo
     * alla pratica e senza toccare il richiedente.
     */
    public function applyToClient(Client $client, Document $document): bool
    {
        $read = $this->read($document);

        if ($read === null) {
            return false;
        }

        [$kind, $fields, $values] = $read;

        $this->fillEmpty($client, $values);
        $this->complete($client, $document, $kind, $fields, false);

        return true;
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}|null profilo, campi letti e valori dell'anagrafica */
    private function read(Document $document): ?array
    {
        $document->loadMissing('documentType');
        $kind = $document->documentType?->ai_kind;

        if (! in_array($kind, ['identita', 'codice_fiscale', 'questionario_kyc'], true)) {
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
            'citizenship' => $kind === 'questionario_kyc' ? $this->text($fields['citizenship'] ?? null) : null,
        ], fn (mixed $value): bool => $value !== null);

        return [$kind, $fields, $values];
    }

    /** Estremi del documento, residenza dalla carta d'identità e domicilio dal questionario. */
    private function complete(?Client $client, Document $document, string $kind, array $fields, bool $company): void
    {
        if ($kind === 'identita') {
            $this->identityDetails($document, $fields);

            if ($client !== null && ! $company) {
                $this->fillAddress($client, self::RESIDENCE_TYPE, 'Residenza', [
                    'street' => $this->text($fields['residence_street'] ?? null),
                    'city' => $this->text($fields['residence_city'] ?? null),
                    'province' => ($province = $this->text($fields['residence_province'] ?? null)) === null ? null : strtoupper($province),
                    'zip_code' => $this->text($fields['residence_zip'] ?? null),
                ]);
            }
        }

        if ($kind === 'questionario_kyc' && $client !== null && ! $company) {
            $this->domicile($client, $fields);
        }
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

    /**
     * L'indirizzo scritto nel questionario è il domicilio (la residenza è quella del documento d'identità): si completano i campi
     * vuoti del domicilio del cliente, senza toccare quelli già compilati.
     *
     * @param  array<string, mixed>  $fields
     */
    private function domicile(Client $client, array $fields): void
    {
        $this->fillAddress($client, self::DOMICILE_TYPE, 'Domicilio', [
            'street' => $this->text($fields['address_street'] ?? null),
            'city' => $this->text($fields['address_city'] ?? null),
            'province' => ($province = $this->text($fields['address_province'] ?? null)) === null ? null : strtoupper($province),
            'zip_code' => $this->text($fields['address_zip'] ?? null),
        ]);
    }

    /**
     * Scrive l'indirizzo del tipo dato: se il cliente non lo ha lo crea, se c'è (anche vuoto) completa solo i campi vuoti.
     *
     * @param  array<string, string|null>  $address
     */
    private function fillAddress(Client $client, int $type, string $name, array $address): void
    {
        $address = array_filter($address, fn (?string $value): bool => $value !== null);

        if ($address === []) {
            return;
        }

        $existing = $client->addresses()->where('address_type_id', $type)->first();

        if ($existing === null) {
            $client->addresses()->create(['name' => $name, 'address_type_id' => $type] + $address);

            return;
        }

        $this->fillEmptyModel($existing, ['name' => $name] + $address);
    }

    /** @param array<string, mixed> $values */
    private function fillEmptyModel(\Illuminate\Database\Eloquent\Model $model, array $values): void
    {
        $empty = array_filter($values, fn (mixed $value, string $key): bool => blank($model->{$key}), ARRAY_FILTER_USE_BOTH);

        if ($empty !== []) {
            $model->forceFill($empty)->save();
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
