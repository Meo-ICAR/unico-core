<?php

namespace Unico\Core\Clients;

use Unico\Core\Models\Branch;
use Unico\Core\Models\Client;
use Unico\Core\Models\ClientRelation;
use RuntimeException;

/**
 * Scrive sul cliente azienda i dati del registro imprese, registra i soci come Client
 * e li lega alla società in client_relations (company_id = id del cliente azienda).
 */
class CompanyShareholderImporter
{
    /**
     * @param  array<string, mixed>  $company  blocco `company` di OpenApiCompanyService::fetchCompany()
     */
    public function syncCompany(Client $client, array $company): void
    {
        $balance = (array) ($company['balance'] ?? []);

        $client->forceFill(array_filter([
            'name' => $company['name'] ?? null,
            'is_person' => false,
            'vat_number' => $company['vat'] ?? null,
            'tax_code' => $client->tax_code ?: ($company['tax_code'] ?? null),
            'pec' => $company['pec'] ?? null,
            'ateco_code' => $company['ateco'] ?? null,
            'cciaa_registration' => $company['cciaa'] ?? null,
            'legal_form' => $company['legal_form'] ?? null,
            'sdi_code' => $company['sdi_code'] ?? null,
            'activity_status' => $company['activity_status'] ?? null,
            'company_started_at' => $company['started_at'] ?? null,
            'share_capital' => $balance['share_capital'] ?? null,
            'employees' => $balance['employees'] ?? null,
            'turnover' => $balance['turnover'] ?? null,
            'net_worth' => $balance['net_worth'] ?? null,
            'balance_year' => $balance['year'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''))->forceFill([
            'is_person' => false,
            'registry_updated_at' => now(),
        ])->save();
    }

    /**
     * @param  array<int, array{is_company: bool, name: ?string, first_name: ?string, tax_code: ?string, percent: ?float}>  $shareholders
     * @return array{created: int, linked: int}
     */
    public function import(Client $company, array $shareholders): array
    {
        $created = 0;
        $linked = 0;

        foreach ($shareholders as $holder) {
            $client = Client::query()->where('tax_code', $holder['tax_code'])->first();

            if ($client === null) {
                $client = Client::create(array_merge([
                    'company_id' => $company->company_id,
                    'name' => $holder['name'],
                    'first_name' => $holder['is_company'] ? null : $holder['first_name'],
                    'is_person' => ! $holder['is_company'],
                    'tax_code' => $holder['tax_code'],
                    'vat_number' => $holder['is_company'] ? $holder['tax_code'] : null,
                ], $holder['is_company'] ? [] : $this->birthData($holder['tax_code'])));
                $created++;
            }

            $relation = ClientRelation::query()->updateOrCreate(
                ['company_id' => $company->getKey(), 'client_id' => $client->getKey()],
                [
                    'shares_percentage' => $holder['percent'],
                    'is_titolare' => ($holder['percent'] ?? 0) > 50,
                ],
            );

            $linked += $relation->wasRecentlyCreated ? 1 : 0;
        }

        $this->assignDefaultAdministrator($company);

        return ['created' => $created, 'linked' => $linked];
    }

    /**
     * @param  array<string, ?string>  $office
     */
    public function syncRegisteredOffice(Client $company, array $office): void
    {
        if (blank($office['address'] ?? null)) {
            return;
        }

        if ($company->company_id === null) {
            throw new RuntimeException('Il cliente azienda non ha company_id: la sede legale non può essere salvata.');
        }

        $branch = $company->branches()->where('is_main_office', true)->first()
            ?? new Branch(['name' => 'Sede legale', 'is_main_office' => true]);

        $branch->fill(array_filter($office, fn ($value) => filled($value)))->forceFill([
            'branchable_type' => $company->getMorphClass(),
            'branchable_id' => $company->getKey(),
            'company_id' => $company->company_id,
        ])->save();
    }

    private function assignDefaultAdministrator(Client $company): void
    {
        if (filled($company->legal_representative_id)) {
            return;
        }

        $relation = ClientRelation::query()
            ->where('company_id', $company->getKey())
            ->whereIn('client_id', Client::query()->where('is_person', true)->select('id'))
            ->orderByDesc('is_titolare')
            ->orderByDesc('shares_percentage')
            ->first();

        if ($relation !== null) {
            $company->forceFill(['legal_representative_id' => $relation->client_id])->save();
        }
    }

    /**
     * Data di nascita e sesso dal codice fiscale, senza il codice catastale del comune.
     *
     * @return array{birth_date?: string, sex?: string}
     */
    private function birthData(?string $taxCode): array
    {
        $code = strtoupper(trim((string) $taxCode));
        $months = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5, 'H' => 6, 'L' => 7, 'M' => 8, 'P' => 9, 'R' => 10, 'S' => 11, 'T' => 12];

        if (! preg_match('/^[A-Z]{6}\d{2}[A-Z]\d{2}/', $code) || ! isset($months[$code[8]])) {
            return [];
        }

        $year = (int) substr($code, 6, 2);
        $day = (int) substr($code, 9, 2);
        $sex = $day > 40 ? 'F' : 'M';
        $day = $day > 40 ? $day - 40 : $day;
        $fullYear = ($year > (int) date('y') ? 1900 : 2000) + $year;

        if (! checkdate($months[$code[8]], $day, $fullYear)) {
            return [];
        }

        return [
            'birth_date' => sprintf('%04d-%02d-%02d', $fullYear, $months[$code[8]], $day),
            'sex' => $sex,
        ];
    }
}
