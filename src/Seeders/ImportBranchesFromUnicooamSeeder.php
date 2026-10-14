<?php

namespace Unico\Core\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Importa le sedi da `unicooam.branches` nel core. Le sedi dei fornitori (chiave UUID in unicooam)
 * si agganciano al fornitore del core con la stessa partita IVA o lo stesso codice fiscale, passando
 * dall'anagrafica `proforma.fornitoris` che conserva gli UUID, e diventano il suo `branch_id`.
 * Le sedi dell'azienda vanno sull'azienda Races Finance del core. Ripetibile.
 */
class ImportBranchesFromUnicooamSeeder extends Seeder
{
    private const SORGENTE = 'unicooam';

    private const ANAGRAFICA_UUID = 'proforma';

    public function run(): void
    {
        $db = DB::connection(config('unico-core.connection'));
        $sorgente = self::SORGENTE;
        $anagrafica = self::ANAGRAFICA_UUID;

        $company = $db->table('companies')->where('name', 'like', 'Races Finance%')->value('id');

        foreach ($db->select("select * from {$sorgente}.branches where deleted_at is null order by id") as $branch) {
            $owner = $branch->branchable_type === 'company'
                ? ['company', (string) $company]
                : $this->fornitore($db, $anagrafica, $branch->branchable_id);

            if ($owner === null || ($owner[0] === 'company' && $company === null)) {
                continue;
            }

            [$type, $ownerId] = $owner;
            $attributes = [
                'company_id' => $company,
                'name' => trim($branch->name),
                'branchable_type' => $type,
                'branchable_id' => $ownerId,
            ];

            $values = [
                'address' => $branch->address,
                'street_number' => $branch->street_number,
                'city' => $branch->city,
                'zip_code' => $branch->zip_code,
                'province' => $branch->province,
                'region' => $branch->region,
                'is_main_office' => $branch->is_main_office,
                'is_active' => $branch->is_active,
                'manager_first_name' => $branch->manager_first_name,
                'manager_last_name' => $branch->manager_last_name,
                'manager_tax_code' => $branch->manager_tax_code,
                'founded_at' => $branch->founded_at,
                'dismissed_at' => $branch->dismissed_at,
                'updated_at' => now(),
            ];

            $existing = $db->table('branches')->where($attributes)->where('city', $branch->city)->value('id');

            if ($existing === null) {
                $existing = $db->table('branches')->insertGetId($attributes + $values + ['created_at' => $branch->created_at ?? now()]);
            }

            if ($type === 'fornitore') {
                $db->table('fornitoris')->where('id', $ownerId)->whereNull('branch_id')->update(['branch_id' => $existing]);
            }
        }
    }

    /** @return array{0: string, 1: string}|null */
    private function fornitore($db, string $anagrafica, string $uuid): ?array
    {
        $rows = $db->select(
            "select f.id from {$anagrafica}.fornitoris pf
             join fornitoris f on (pf.piva is not null and pf.piva <> '' and f.piva = pf.piva) or (pf.cf is not null and pf.cf <> '' and f.cf = pf.cf)
             where pf.id = ? limit 1",
            [$uuid],
        );

        return $rows === [] ? null : ['fornitore', (string) $rows[0]->id];
    }
}
