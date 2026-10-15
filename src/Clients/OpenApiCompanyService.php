<?php

namespace Unico\Core\Clients;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Unico\Core\Models\Client;

/**
 * Dati società da Openapi.it (GET /IT-advanced/{partitaIva}).
 * Se la risposta non contiene i soci, chiede anche /IT-shareholders/{partitaIva}.
 */
class OpenApiCompanyService
{
    /**
     * @return array{
     *     company: array{
     *         name: ?string, vat: ?string, tax_code: ?string, pec: ?string, ateco: ?string,
     *         cciaa: ?string, activity_status: ?string, address: ?string, legal_form: ?string,
     *         sdi_code: ?string, started_at: ?string,
     *         balance: array{year: ?int, employees: ?int, turnover: int|float|null, net_worth: int|float|null, share_capital: int|float|null},
     *         office: array<string, ?string>
     *     },
     *     shareholders: array<int, array{is_company: bool, name: ?string, first_name: ?string, tax_code: ?string, percent: ?float}>
     * }
     */
    /**
     * Legge IT-advanced e scrive sul cliente azienda: anagrafica, sede legale e soci come clienti collegati.
     *
     * @return array<string, mixed>
     */
    public function apply(Client $company, ?string $vat = null): array
    {
        $vat = $vat ?? (string) ($company->vat_number ?: $company->tax_code);
        $result = $this->fetchCompany($vat);
        $importer = new CompanyShareholderImporter;
        $importer->syncCompany($company, $result['company']);
        $result['outcome'] = $importer->import($company, $result['shareholders']);
        $importer->syncRegisteredOffice($company, $result['company']['office']);

        return $result;
    }

    public function fetchCompany(string $vat): array
    {
        $vat = preg_replace('/^IT/i', '', trim($vat)) ?? '';
        $error = controllaPartitaIva($vat);
        if ($error !== null || strlen($vat) !== 11) {
            throw new RuntimeException($error ?? 'La Partita IVA italiana non è valida.');
        }

        $advanced = $this->get('IT-advanced', $vat);
        $data = $this->firstItem($advanced);

        if ($data === []) {
            throw new RuntimeException('Nessuna azienda trovata per la partita IVA indicata.');
        }

        $shareholders = (array) ($data['shareHolders'] ?? $data['shareholders'] ?? []);

        if ($shareholders === []) {
            $shareholders = (array) ($this->get('IT-shareholders', $vat)['data'] ?? []);
        }

        $office = (array) ($data['address']['registeredOffice'] ?? []);

        return [
            'company' => [
                'name' => $data['companyName'] ?? null,
                'vat' => isset($data['vatCode']) ? (string) $data['vatCode'] : null,
                'tax_code' => isset($data['taxCode']) ? (string) $data['taxCode'] : null,
                'pec' => $data['pec'] ?? null,
                'ateco' => $this->atecoCode($data),
                'legal_form' => $data['detailedLegalForm']['description'] ?? null,
                'started_at' => $data['startDate'] ?? null,
                'balance' => $this->lastBalance($data),
                'sdi_code' => $data['sdiCode'] ?? null,
                'office' => [
                    'address' => $this->street($office),
                    'street_number' => isset($office['streetNumber']) ? (string) $office['streetNumber'] : null,
                    'city' => $office['town'] ?? null,
                    'zip_code' => isset($office['zipCode']) ? (string) $office['zipCode'] : null,
                    'province' => $office['province'] ?? null,
                    'region' => $office['region']['description'] ?? null,
                    'founded_at' => $data['startDate'] ?? null,
                ],
                'cciaa' => filled($data['cciaa'] ?? null) ? trim($data['cciaa'].' '.($data['reaCode'] ?? '')) : null,
                'activity_status' => $data['activityStatus'] ?? null,
                'address' => $this->formatAddress($office),
            ],
            'shareholders' => collect($shareholders)
                ->map(fn (array $holder): array => $this->mapShareholder($holder))
                ->filter(fn (array $holder): bool => filled($holder['tax_code']) && filled($holder['name']))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $service, string $vat): array
    {
        $token = config('unico-core.openapi.token');

        if (blank($token)) {
            throw new RuntimeException('Token Openapi non configurato (OPENAPI_TOKEN).');
        }

        $url = rtrim((string) config('unico-core.openapi.company_url'), '/').'/'.$service.'/'.urlencode($vat);

        try {
            $response = Http::withToken((string) $token)
                ->acceptJson()
                ->timeout((int) config('unico-core.openapi.timeout', 30))
                ->get($url);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Openapi non raggiungibile.', 0, $e);
        }

        if (! $response->successful() || $response->json('success') === false) {
            $message = $response->json('message') ?: mb_substr($response->body(), 0, 300);

            throw new RuntimeException('Errore Openapi (HTTP '.$response->status().')'.($message !== '' ? ': '.$message : '.'));
        }

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{year: ?int, employees: ?int, turnover: int|float|null, net_worth: int|float|null, share_capital: int|float|null}
     */
    private function lastBalance(array $data): array
    {
        $balance = (array) ($data['balanceSheets']['last'] ?? []);

        if ($balance === []) {
            $balance = (array) collect($data['balanceSheets']['all'] ?? [])
                ->first(fn (array $year): bool => isset($year['turnover']) || isset($year['netWorth']));
        }

        return [
            'year' => isset($balance['year']) ? (int) $balance['year'] : null,
            'employees' => isset($balance['employees']) ? (int) $balance['employees'] : null,
            'turnover' => $balance['turnover'] ?? null,
            'net_worth' => $balance['netWorth'] ?? null,
            'share_capital' => $balance['shareCapital'] ?? null,
        ];
    }

    /**
     * Preferisce ATECO 2022, poi 2007, poi il codice sintetico.
     *
     * @param  array<string, mixed>  $data
     */
    private function atecoCode(array $data): ?string
    {
        $classification = (array) ($data['atecoClassification'] ?? []);

        foreach (['ateco2022', 'ateco2007', 'ateco'] as $key) {
            $entry = $classification[$key] ?? null;
            $code = is_array($entry) ? ($entry['code'] ?? null) : $entry;
            $formatted = $this->formatAteco($code);
            if ($formatted !== null) {
                return $formatted;
            }
        }

        return null;
    }

    private function formatAteco(mixed $code): ?string
    {
        $code = trim((string) $code);

        if ($code === '') {
            return null;
        }

        return preg_match('/^\d{4,6}$/', $code) === 1 ? substr($code, 0, 2).'.'.substr($code, 2) : $code;
    }

    /**
     * @param  array<string, mixed>  $office
     */
    private function street(array $office): ?string
    {
        $named = trim((string) ($office['streetName'] ?? ''));
        if ($named !== '') {
            return $named;
        }

        $composed = trim(implode(' ', array_filter([
            $office['toponym'] ?? null,
            $office['street'] ?? null,
            $office['streetNumber'] ?? null,
        ], fn ($part) => filled($part))));

        return $composed !== '' ? $composed : null;
    }

    /**
     * @param  array<string, mixed>  $office
     */
    private function formatAddress(array $office): ?string
    {
        $place = trim(implode(' ', array_filter([
            $office['zipCode'] ?? null,
            $office['town'] ?? null,
            isset($office['province']) ? '('.$office['province'].')' : null,
        ], fn ($part) => filled($part))));

        return implode(', ', array_filter([$this->street($office), $place !== '' ? $place : null])) ?: null;
    }

    /**
     * @param  array<string, mixed>  $holder
     * @return array{is_company: bool, name: ?string, first_name: ?string, tax_code: ?string, percent: ?float}
     */
    private function mapShareholder(array $holder): array
    {
        $tax = isset($holder['taxCode']) ? strtoupper(trim((string) $holder['taxCode'])) : null;
        $person = filled($holder['surname'] ?? null) || (is_string($tax) && strlen($tax) === 16);

        return [
            'is_company' => ! $person && filled($holder['companyName'] ?? null),
            'name' => $person
                ? ($holder['surname'] ?? $holder['companyName'] ?? null)
                : ($holder['companyName'] ?? $holder['surname'] ?? null),
            'first_name' => $person ? ($holder['name'] ?? null) : null,
            'tax_code' => $tax !== '' ? $tax : null,
            'percent' => isset($holder['percentShare']) ? (float) $holder['percentShare'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function firstItem(array $json): array
    {
        $data = $json['data'] ?? [];

        return array_is_list($data) ? (array) ($data[0] ?? []) : (array) $data;
    }
}
