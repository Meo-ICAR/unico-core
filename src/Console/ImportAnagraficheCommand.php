<?php

namespace Unico\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copia anagrafiche dai dump di produzione (unicooam, proforma, unicoloan, unicoagent)
 * verso il database condiviso unico-core. Senza --execute elenca solo cosa farebbe.
 */
class ImportAnagraficheCommand extends Command
{
    protected $signature = 'unico-core:import-anagrafiche
                            {--execute : Scrive sul database core; di default è un dry-run}
                            {--oam= : Database UnicoOAM (default config unico-core.import.oam)}
                            {--proforma= : Database Proforma (default config unico-core.import.proforma)}
                            {--loan= : Database UnicoLoan (default config unico-core.import.loan)}
                            {--agent= : Database UnicoAgent (default config unico-core.import.agent)}';

    protected $description = 'Importa anagrafiche, stati pratica e pratiches verso unico-core';

    /** @var array<int, array{0: string, 1: int|string}> */
    private array $report = [];

    /** @var array<string, int> UUID company produzione → id core */
    private array $companyIds = [];

    /** @var array<string, int> codice pratica produzione → id core */
    private array $praticaIds = [];

    public function handle(): int
    {
        $destName = (string) config('unico-core.connection');
        $dest = DB::connection($destName);
        $execute = (bool) $this->option('execute');

        $this->info($execute
            ? "Scrittura su {$dest->getDatabaseName()} (connessione {$destName})"
            : "Dry-run: nessuna scrittura su {$dest->getDatabaseName()}");

        try {
            $oam = $this->source($this->sourceName('oam'));
            $proforma = $this->source($this->sourceName('proforma'));
            $loan = $this->source($this->sourceName('loan'));
            $agent = $this->source($this->sourceName('agent'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($execute) {
            $dest->statement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            $this->importEmployeeTypes($oam, $dest, $execute);
            $this->importCompanies($oam, $dest, $execute);
            $this->importEmployees($oam, $dest, $execute);
            $this->importOamClients($oam, $dest, $execute);
            $this->importProformaClients($proforma, $dest, $execute);
            $this->importFornitori($proforma, $dest, $execute);
            $this->importClientis($proforma, $dest, $execute);
            $this->importDocumentTypes($oam, $loan, $agent, $dest, $execute);
            $this->importUsers($dest, $execute);
            $this->importTipoProdotto($proforma, $dest, $execute);
            $this->importPraticaStati($proforma, $dest, $execute);
            $this->importPratiches($proforma, $dest, $execute);
        } finally {
            if ($execute) {
                $dest->statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        $this->newLine();
        $this->table(['Passo', 'Esito'], $this->report);

        if (! $execute) {
            $this->comment('Per scrivere: php artisan unico-core:import-anagrafiche --execute');
        }

        return self::SUCCESS;
    }

    private function sourceName(string $key): string
    {
        $option = $this->option($key);

        return filled($option) ? (string) $option : (string) config("unico-core.import.{$key}");
    }

    private function source(string $database): Connection
    {
        $core = config('unico-core.connection');
        $base = config("database.connections.{$core}");
        if (! is_array($base)) {
            throw new \RuntimeException("Manca la connessione {$core} in config/database.php");
        }

        $name = 'import_'.$database;
        config(["database.connections.{$name}" => array_merge($base, ['database' => $database])]);
        $conn = DB::connection($name);
        $conn->getPdo();

        return $conn;
    }

    private function importEmployeeTypes(Connection $oam, Connection $dest, bool $execute): void
    {
        $rows = $oam->table('employee_types')->orderBy('id')->get();
        $inserted = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $payload = $this->onlyColumns((array) $row, $dest, 'employee_types');
            $existing = $dest->table('employee_types')->where('id', $payload['id'])->orWhere('name', $payload['name'] ?? null)->first();
            if ($existing) {
                $updated++;
                if ($execute) {
                    $dest->table('employee_types')->where('id', $existing->id)->update($this->withoutId($payload));
                }
            } else {
                $inserted++;
                if ($execute) {
                    $dest->table('employee_types')->insert($payload);
                }
            }
        }

        $this->note('employee_types da unicooam', "{$rows->count()} letti, {$inserted} nuovi, {$updated} aggiornati");
    }

    private function importCompanies(Connection $oam, Connection $dest, bool $execute): void
    {
        $rows = $oam->table('companies')->orderBy('name')->get();
        if ($rows->isEmpty()) {
            $this->note('companies da unicooam', 'nessuna riga');

            return;
        }

        foreach ($rows as $row) {
            $src = (array) $row;
            $uuid = (string) $src['id'];
            $payload = $this->onlyColumns($src, $dest, 'companies');
            unset($payload['id']);

            $existing = $dest->table('companies')
                ->when(filled($src['vat_number'] ?? null), fn ($q) => $q->orWhere('vat_number', $src['vat_number']))
                ->orWhere('name', $src['name'] ?? '')
                ->first();

            if ($existing === null && $dest->table('companies')->count() === 1) {
                $existing = $dest->table('companies')->orderBy('id')->first();
            }

            if ($existing) {
                $this->companyIds[$uuid] = (int) $existing->id;
                if ($execute) {
                    $dest->table('companies')->where('id', $existing->id)->update($payload);
                }
                $this->note('companies da unicooam', "{$src['name']} → id core {$existing->id} (aggiornata, UUID {$uuid})");
            } else {
                $newId = $execute
                    ? $dest->table('companies')->insertGetId($payload)
                    : ($dest->table('companies')->max('id') + 1);
                $this->companyIds[$uuid] = (int) $newId;
                $this->note('companies da unicooam', "{$src['name']} → id core {$newId} (nuova)");
            }
        }
    }

    private function importEmployees(Connection $oam, Connection $dest, bool $execute): void
    {
        if (! $this->hasTable($oam, 'employees')) {
            $this->note('employees da unicooam', 'tabella assente');

            return;
        }

        $rows = $oam->table('employees')->orderBy('id')->get();
        $inserted = 0;
        $updated = 0;
        $userIds = $dest->table('users')->pluck('id')->all();
        $branchIds = $this->hasTable($dest, 'branches') ? $dest->table('branches')->pluck('id')->all() : [];

        foreach ($rows as $row) {
            $src = (array) $row;
            $payload = $this->onlyColumns($src, $dest, 'employees');
            $payload['company_id'] = $this->companyIds[(string) ($src['company_id'] ?? '')] ?? null;
            if (! in_array($payload['user_id'] ?? null, $userIds, true)) {
                $payload['user_id'] = null;
            }
            if (! in_array($payload['branch_id'] ?? null, $branchIds, true)) {
                $payload['branch_id'] = null;
            }
            foreach (['created_by', 'updated_by', 'deleted_by'] as $audit) {
                if (! in_array($payload[$audit] ?? null, $userIds, true)) {
                    $payload[$audit] = null;
                }
            }

            $existing = $dest->table('employees')->where('id', $payload['id'])->first()
                ?? (filled($payload['email'] ?? null) ? $dest->table('employees')->where('email', $payload['email'])->first() : null);

            if ($existing) {
                $updated++;
                if ($execute) {
                    $dest->table('employees')->where('id', $existing->id)->update($this->withoutId($payload));
                }
            } else {
                $inserted++;
                if ($execute) {
                    $dest->table('employees')->insert($payload);
                }
            }
        }

        $this->note('employees da unicooam', "{$rows->count()} letti, {$inserted} nuovi, {$updated} aggiornati");
    }

    private function importOamClients(Connection $oam, Connection $dest, bool $execute): void
    {
        if (! $this->hasTable($oam, 'clients')) {
            $this->note('clients da unicooam', 'tabella assente nel dump OAM: niente da importare (i clienti banca stanno in proforma.clientis)');

            return;
        }

        $rows = $oam->table('clients')->orderBy('id')->get();
        $this->note('clients da unicooam', $rows->count().' letti (non copiati in questo passo se vuoi un mapping dedicato)');
    }

    private function importProformaClients(Connection $proforma, Connection $dest, bool $execute): void
    {
        if (! $this->hasTable($proforma, 'clients')) {
            $this->note('clients da proforma', 'tabella assente');

            return;
        }

        $rows = $proforma->table('clients')->orderBy('id')->get();
        $inserted = 0;
        $updated = 0;
        $companyId = $this->defaultCompanyId($dest);
        $typeIds = $this->hasTable($dest, 'client_types') ? $dest->table('client_types')->pluck('id')->all() : [];

        foreach ($proforma->table('companies')->get() as $company) {
            $this->companyIds[(string) $company->id] = $companyId;
        }

        foreach ($rows as $row) {
            $src = (array) $row;
            $payload = $this->onlyColumns($src, $dest, 'clients');
            $payload['company_id'] = $this->mapCompanyId($src['company_id'] ?? null, $companyId);
            $payload['user_id'] = null;
            $typeId = $payload['client_type_id'] ?? null;
            if ($typeId !== null && ! in_array((int) $typeId, array_map('intval', $typeIds), true)) {
                $payload['client_type_id'] = null;
            }

            $existing = $dest->table('clients')->where('id', $payload['id'] ?? 0)->first();
            if ($existing === null && filled($src['tax_code'] ?? null)) {
                $existing = $dest->table('clients')->where('tax_code', $src['tax_code'])->first();
            }
            if ($existing === null && filled($src['email'] ?? null)) {
                $existing = $dest->table('clients')->where('email', $src['email'])->first();
            }

            if ($existing) {
                $updated++;
                if ($execute) {
                    $dest->table('clients')->where('id', $existing->id)->update($this->withoutId($payload));
                }
            } else {
                $inserted++;
                if ($execute) {
                    $dest->table('clients')->insert($payload);
                }
            }
        }

        $this->note('clients da proforma', "{$rows->count()} letti, {$inserted} nuovi, {$updated} aggiornati");
    }

    private function importUsers(Connection $dest, bool $execute): void
    {
        $companyId = $this->defaultCompanyId($dest);
        $password = Hash::make('password');

        $admin = [
            'name' => 'Super Admin',
            'email' => 'hassistosrl@gmail.com',
            'password' => $password,
            'role' => 'super_admin',
            'company_id' => $companyId,
            'profile_type' => null,
            'profile_id' => null,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $this->upsertUser($dest, $admin, $execute);
        $this->note('user Filament', 'hassistosrl@gmail.com ruolo super_admin (password: password)');

        $employees = $dest->table('employees')->whereNotNull('email')->where('email', '!=', '')->orderBy('id')->get();
        $asEmployee = 0;
        $asClient = 0;
        $skipped = 0;

        foreach ($employees as $employee) {
            $email = Str::lower(trim((string) $employee->email));
            if ($email === '') {
                continue;
            }

            if (Str::endsWith($email, '@races.it')) {
                $userId = $this->upsertUser($dest, [
                    'name' => $employee->name ?: $email,
                    'email' => $email,
                    'password' => $password,
                    'role' => 'employee',
                    'company_id' => $employee->company_id ?: $companyId,
                    'profile_type' => 'employee',
                    'profile_id' => $employee->id,
                    'email_verified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $execute);
                if ($execute && $userId) {
                    $dest->table('employees')->where('id', $employee->id)->update(['user_id' => $userId]);
                }
                $asEmployee++;

                continue;
            }

            $client = $dest->table('clients')->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($client === null) {
                $skipped++;
                $this->note('user saltato', "{$employee->name} ({$email}): non @races.it e nessun client con la stessa email");

                continue;
            }

            $userId = $this->upsertUser($dest, [
                'name' => $client->name ?: ($employee->name ?: $email),
                'email' => $email,
                'password' => $password,
                'role' => 'client',
                'company_id' => $client->company_id ?: $companyId,
                'profile_type' => 'client',
                'profile_id' => $client->id,
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ], $execute);
            if ($execute && $userId) {
                $dest->table('clients')->where('id', $client->id)->update(['user_id' => $userId]);
            }
            $asClient++;
        }

        $this->note('users da employees', "{$asEmployee} ruolo employee (@races.it), {$asClient} legati a client, {$skipped} senza corrispondenza");
    }

    /** @param  array<string, mixed>  $payload */
    private function upsertUser(Connection $dest, array $payload, bool $execute): ?int
    {
        $existing = $dest->table('users')->where('email', $payload['email'])->first();
        if (! $execute) {
            return $existing?->id;
        }
        if ($existing) {
            $dest->table('users')->where('id', $existing->id)->update([
                'name' => $payload['name'],
                'role' => $payload['role'],
                'company_id' => $payload['company_id'],
                'profile_type' => $payload['profile_type'],
                'profile_id' => $payload['profile_id'],
            ]);

            return (int) $existing->id;
        }

        return (int) $dest->table('users')->insertGetId($payload);
    }

    private function importTipoProdotto(Connection $proforma, Connection $dest, bool $execute): void
    {
        if (! $this->hasTable($proforma, 'tipoprodotto') || ! $this->hasTable($dest, 'tipoprodotto')) {
            $this->note('tipoprodotto da proforma', 'tabella assente');

            return;
        }

        $rows = $proforma->table('tipoprodotto')->orderBy('id')->get();
        $inserted = 0;
        $updated = 0;
        $allowedProvvigioni = ['Lordo', 'Erogato', 'Netto'];

        foreach ($rows as $row) {
            $src = (array) $row;
            $payload = $this->onlyColumns($src, $dest, 'tipoprodotto');
            $tipoProv = $payload['tipo_provvigioni'] ?? null;
            if ($tipoProv !== null && ! in_array($tipoProv, $allowedProvvigioni, true)) {
                $payload['tipo_provvigioni'] = null;
            }

            $existing = null;
            if (isset($payload['id'])) {
                $existing = $dest->table('tipoprodotto')->where('id', $payload['id'])->first();
            }
            if ($existing === null && filled($src['tipo_prodotto'] ?? null)) {
                $existing = $dest->table('tipoprodotto')->whereRaw('LOWER(tipo_prodotto) = ?', [Str::lower((string) $src['tipo_prodotto'])])->first();
            }
            if ($existing === null && filled($src['name'] ?? null) && blank($src['tipo_prodotto'] ?? null)) {
                $existing = $dest->table('tipoprodotto')->where('name', $src['name'])->whereNull('tipo_prodotto')->first();
            }

            if ($existing) {
                $updated++;
                if ($execute) {
                    $dest->table('tipoprodotto')->where('id', $existing->id)->update($this->withoutId($payload));
                }
            } else {
                $inserted++;
                if ($execute) {
                    $dest->table('tipoprodotto')->insert($payload);
                }
            }
        }

        $this->note('tipoprodotto da proforma', "{$rows->count()} letti, {$inserted} nuovi, {$updated} aggiornati");
    }

    private function importPraticaStati(Connection $proforma, Connection $dest, bool $execute): void
    {
        if (! $this->hasTable($proforma, 'pratiches') || ! $this->hasTable($dest, 'pratica_stati')) {
            $this->note('pratica_stati da pratiches', 'tabella assente');

            return;
        }

        $flags = [];
        if ($this->hasTable($proforma, 'pratiches_statos')) {
            foreach ($proforma->table('pratiches_statos')->get() as $row) {
                $flags[Str::lower(trim((string) $row->stato_pratica))] = $row;
            }
        }

        $tipoCanon = [];
        if ($this->hasTable($dest, 'tipoprodotto')) {
            foreach ($dest->table('tipoprodotto')->whereNotNull('tipo_prodotto')->get() as $tipo) {
                $tipoCanon[Str::lower((string) $tipo->tipo_prodotto)] = (string) $tipo->tipo_prodotto;
            }
        }

        $pairs = $proforma->table('pratiches')
            ->select('stato_pratica', 'tipo_prodotto')
            ->whereNotNull('stato_pratica')
            ->where('stato_pratica', '!=', '')
            ->distinct()
            ->get();

        $seen = [];
        $inserted = 0;
        $updated = 0;
        $ordine = 0;

        foreach ($pairs as $pair) {
            $codice = trim((string) $pair->stato_pratica);
            if ($codice === '') {
                continue;
            }
            $flag = $flags[Str::lower($codice)] ?? null;
            if ($flag && filled($flag->stato_pratica)) {
                $codice = (string) $flag->stato_pratica;
            }
            $tipoRaw = trim((string) ($pair->tipo_prodotto ?? ''));
            $tipo = $tipoRaw === '' ? '' : ($tipoCanon[Str::lower($tipoRaw)] ?? $tipoRaw);
            $key = $this->statoKey($codice, $tipo);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $ordine++;

            $payload = [
                'codice' => $codice,
                'tipo_finanziamento' => $tipo,
                'name' => $codice,
                'ordine' => $ordine,
                'is_rejected' => (bool) ($flag->isrejected ?? false),
                'is_working' => $flag ? (bool) $flag->isworking : true,
                'is_estingued' => (bool) ($flag->isestingued ?? false),
                'updated_at' => now(),
            ];

            $existing = $dest->table('pratica_stati')
                ->whereRaw('LOWER(codice) = ?', [Str::lower($codice)])
                ->whereRaw('LOWER(COALESCE(tipo_finanziamento, \'\')) = ?', [Str::lower($tipo)])
                ->first();

            if ($existing) {
                $updated++;
                if ($execute) {
                    $dest->table('pratica_stati')->where('id', $existing->id)->update($payload);
                }
            } else {
                $inserted++;
                if ($execute) {
                    $payload['created_at'] = now();
                    $dest->table('pratica_stati')->insert($payload);
                }
            }
        }

        $this->note('pratica_stati da pratiches', "{$pairs->count()} coppie distinct, {$inserted} nuovi, {$updated} aggiornati");
    }

    private function importPratiches(Connection $proforma, Connection $dest, bool $execute): void
    {
        if (! $this->hasTable($proforma, 'pratiches') || ! $this->hasTable($dest, 'pratiches')) {
            $this->note('pratiches da proforma', 'tabella assente');

            return;
        }

        $companyId = $this->defaultCompanyId($dest);
        $tipoIds = [];
        foreach ($dest->table('tipoprodotto')->whereNotNull('tipo_prodotto')->get() as $tipo) {
            $tipoIds[Str::lower((string) $tipo->tipo_prodotto)] = (int) $tipo->id;
        }
        $statoIds = [];
        foreach ($dest->table('pratica_stati')->get() as $stato) {
            $statoIds[$this->statoKey((string) $stato->codice, (string) ($stato->tipo_finanziamento ?? ''))] = (int) $stato->id;
        }
        $fornitoriByPiva = [];
        foreach ($dest->table('fornitoris')->whereNotNull('piva')->where('piva', '!=', '')->get(['id', 'piva']) as $fornitore) {
            $fornitoriByPiva[Str::lower(trim((string) $fornitore->piva))] = (int) $fornitore->id;
        }
        $clientiByName = [];
        foreach ($dest->table('clientis')->whereNotNull('name')->get(['id', 'name']) as $cliente) {
            $clientiByName[Str::lower(trim((string) $cliente->name))] = (int) $cliente->id;
        }

        $rows = $proforma->table('pratiches')->orderBy('id')->get();
        $inserted = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $src = (array) $row;
            $sourceId = (string) ($src['id'] ?? $src['codice_pratica'] ?? '');
            $payload = $this->onlyColumns($src, $dest, 'pratiches');
            unset($payload['id']);
            $payload['company_id'] = $companyId;
            $tipoRaw = trim((string) ($src['tipo_prodotto'] ?? ''));
            $statoRaw = trim((string) ($src['stato_pratica'] ?? ''));
            $payload['tipoprodotto_id'] = $tipoRaw === '' ? null : ($tipoIds[Str::lower($tipoRaw)] ?? null);
            $tipoCanon = $tipoRaw;
            if ($payload['tipoprodotto_id']) {
                foreach ($tipoIds as $name => $id) {
                    if ($id === $payload['tipoprodotto_id']) {
                        $tipoCanon = $name;

                        break;
                    }
                }
            }
            $payload['pratica_stato_id'] = $statoRaw === '' ? null : ($statoIds[$this->statoKey($statoRaw, $tipoCanon)] ?? $statoIds[$this->statoKey($statoRaw, $tipoRaw)] ?? null);
            $piva = Str::lower(trim((string) ($src['partita_iva_agente'] ?? '')));
            $payload['fornitori_id'] = $piva === '' ? null : ($fornitoriByPiva[$piva] ?? null);
            $banca = Str::lower(trim((string) ($src['denominazione_banca'] ?? '')));
            $payload['clienti_id'] = $banca === '' ? null : ($clientiByName[$banca] ?? null);
            if (blank($payload['upload_at'] ?? null)) {
                $payload['upload_at'] = now();
            }
            if (blank($payload['codice_pratica'] ?? null)) {
                $payload['codice_pratica'] = $sourceId;
            }

            $existing = $dest->table('pratiches')->where('codice_pratica', $payload['codice_pratica'])->first();
            if ($existing) {
                $updated++;
                $coreId = (int) $existing->id;
                if ($execute) {
                    $dest->table('pratiches')->where('id', $existing->id)->update($payload);
                }
            } else {
                $inserted++;
                $coreId = null;
                if ($execute) {
                    $coreId = (int) $dest->table('pratiches')->insertGetId($payload);
                }
            }
            if ($sourceId !== '' && $coreId) {
                $this->praticaIds[$sourceId] = $coreId;
                $this->praticaIds[(string) $payload['codice_pratica']] = $coreId;
            }
        }

        $this->note('pratiches da proforma', "{$rows->count()} lette, {$inserted} nuove, {$updated} aggiornate (id codice → bigint)");
    }

    private function statoKey(string $codice, ?string $tipo): string
    {
        return Str::lower(trim($codice)).'|'.Str::lower(trim((string) $tipo));
    }

    private function importFornitori(Connection $proforma, Connection $dest, bool $execute): void
    {
        $rows = $proforma->table('fornitoris')->orderBy('name')->get();
        $inserted = 0;
        $updated = 0;
        $roleIds = $this->hasTable($dest, 'fornitoriroles') ? $dest->table('fornitoriroles')->pluck('id')->all() : [];
        $companyId = $this->defaultCompanyId($dest);

        foreach ($rows as $row) {
            $src = (array) $row;
            $payload = $this->onlyColumns($src, $dest, 'fornitoris');
            unset($payload['id']);
            $payload['company_id'] = $this->mapCompanyId($src['company_id'] ?? null, $companyId);
            if (! in_array($payload['fornitorirole_id'] ?? null, $roleIds, true)) {
                $payload['fornitorirole_id'] = null;
            }
            $payload['user_id'] = null;
            $payload['branch_id'] = null;

            $existing = $this->findFornitore($dest, $src);
            if ($existing) {
                $updated++;
                if ($execute) {
                    $dest->table('fornitoris')->where('id', $existing->id)->update($payload);
                }
            } else {
                $inserted++;
                if ($execute) {
                    $dest->table('fornitoris')->insert($payload);
                }
            }
        }

        $this->note('fornitoris da proforma', "{$rows->count()} letti, {$inserted} nuovi, {$updated} aggiornati (id UUID → bigint)");
    }

    private function importClientis(Connection $proforma, Connection $dest, bool $execute): void
    {
        $rows = $proforma->table('clientis')->orderBy('name')->get();
        $inserted = 0;
        $updated = 0;
        $companyId = $this->defaultCompanyId($dest);

        foreach ($rows as $row) {
            $src = (array) $row;
            $payload = $this->onlyColumns($src, $dest, 'clientis');
            unset($payload['id']);
            $payload['company_id'] = $this->mapCompanyId($src['company_id'] ?? null, $companyId);

            $existing = null;
            if (filled($src['piva'] ?? null)) {
                $existing = $dest->table('clientis')->where('piva', $src['piva'])->first();
            }
            if ($existing === null && filled($src['name'] ?? null)) {
                $existing = $dest->table('clientis')->where('name', $src['name'])->first();
            }

            if ($existing) {
                $updated++;
                if ($execute) {
                    $dest->table('clientis')->where('id', $existing->id)->update($payload);
                }
            } else {
                $inserted++;
                if ($execute) {
                    $dest->table('clientis')->insert($payload);
                }
            }
        }

        $this->note('clientis (banche) da proforma', "{$rows->count()} letti, {$inserted} nuovi, {$updated} aggiornati (id UUID → bigint)");
    }

    private function importDocumentTypes(Connection $oam, Connection $loan, Connection $agent, Connection $dest, bool $execute): void
    {
        $sources = [
            'unicooam' => $oam,
            'unicoloan' => $loan,
            'unicoagent' => $agent,
        ];
        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($sources as $label => $conn) {
            if (! $this->hasTable($conn, 'document_types')) {
                $this->note("document_types da {$label}", 'tabella assente');

                continue;
            }

            $rows = $conn->table('document_types')->orderBy('id')->get();
            $newHere = 0;
            $updHere = 0;

            foreach ($rows as $row) {
                $src = $this->normalizeDocumentType((array) $row);
                $payload = $this->onlyColumns($src, $dest, 'document_types');
                unset($payload['id']);
                $payload['company_id'] = null;
                foreach (['created_by', 'updated_by', 'deleted_by', 'renewed_by_id'] as $fk) {
                    $payload[$fk] = null;
                }
                foreach (['code', 'slug'] as $unique) {
                    if (array_key_exists($unique, $payload) && blank($payload[$unique])) {
                        $payload[$unique] = null;
                    }
                }

                $existing = $this->findDocumentType($dest, $payload);

                if ($existing) {
                    $updHere++;
                    $updated++;
                    if ($execute) {
                        $dest->table('document_types')->where('id', $existing->id)->update($this->fillEmpty($existing, $payload));
                    }
                } else {
                    $newHere++;
                    $inserted++;
                    if ($execute) {
                        if (blank($payload['slug'] ?? null) && filled($payload['name'] ?? null)) {
                            $payload['slug'] = Str::slug((string) $payload['name']);
                            $bySlug = $dest->table('document_types')->where('slug', $payload['slug'])->first();
                            if ($bySlug) {
                                $updHere++;
                                $updated++;
                                $newHere--;
                                $inserted--;
                                $dest->table('document_types')->where('id', $bySlug->id)->update($this->fillEmpty($bySlug, $payload));

                                continue;
                            }
                        }
                        $dest->table('document_types')->insert($payload);
                    }
                }
            }

            $this->note("document_types da {$label}", "{$rows->count()} letti, {$newHere} nuovi, {$updHere} già presenti (merge per slug/nome)");
        }

        $this->note('document_types totale', "{$inserted} nuovi, {$updated} aggiornati, {$skipped} saltati");
    }

    /** @param  array<string, mixed>  $src */
    private function normalizeDocumentType(array $src): array
    {
        $map = [
            'is_endMonth' => 'is_end_month',
            'is_AiAbstract' => 'is_ai_abstract',
            'is_AiCheck' => 'is_ai_check',
            'AiPattern' => 'ai_pattern',
        ];
        foreach ($map as $old => $new) {
            if (array_key_exists($old, $src) && ! array_key_exists($new, $src)) {
                $src[$new] = $src[$old];
            }
            unset($src[$old]);
        }
        if (array_key_exists('expire_days_before', $src) && ! array_key_exists('notify_days_before', $src) && $src['expire_days_before'] !== null) {
            $src['notify_days_before'] = json_encode([(int) $src['expire_days_before']]);
        }
        unset($src['expire_days_before']);

        return $src;
    }

    /** @param  array<string, mixed>  $payload */
    private function findDocumentType(Connection $dest, array $payload): ?object
    {
        foreach (['code', 'slug', 'name'] as $key) {
            if (filled($payload[$key] ?? null)) {
                $hit = $dest->table('document_types')->where($key, $payload[$key])->first();
                if ($hit) {
                    return $hit;
                }
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $payload */
    private function fillEmpty(object $existing, array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            $current = $existing->{$key} ?? null;
            if ($current === null || $current === '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $src */
    private function onlyColumns(array $src, Connection $dest, string $table): array
    {
        $columns = Schema::connection($dest->getName())->getColumnListing($table);
        $out = [];
        foreach ($columns as $column) {
            if (array_key_exists($column, $src)) {
                $out[$column] = $src[$column];
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $payload */
    private function withoutId(array $payload): array
    {
        unset($payload['id']);

        return $payload;
    }

    /** @param  array<string, mixed>  $src */
    private function findFornitore(Connection $dest, array $src): ?object
    {
        if (filled($src['piva'] ?? null)) {
            $hit = $dest->table('fornitoris')->where('piva', $src['piva'])->first();
            if ($hit) {
                return $hit;
            }
        }
        if (filled($src['cf'] ?? null)) {
            $hit = $dest->table('fornitoris')->where('cf', $src['cf'])->first();
            if ($hit) {
                return $hit;
            }
        }
        if (filled($src['name'] ?? null)) {
            return $dest->table('fornitoris')->where('name', $src['name'])->first();
        }

        return null;
    }

    private function mapCompanyId(mixed $sourceId, ?int $fallback): ?int
    {
        if ($sourceId === null || $sourceId === '') {
            return $fallback;
        }
        if (is_numeric($sourceId) && isset($this->companyIds[(string) $sourceId])) {
            return $this->companyIds[(string) $sourceId];
        }

        return $this->companyIds[(string) $sourceId] ?? $fallback;
    }

    private function defaultCompanyId(Connection $dest): ?int
    {
        if ($this->companyIds !== []) {
            return (int) reset($this->companyIds);
        }

        return $dest->table('companies')->orderBy('id')->value('id');
    }

    private function hasTable(Connection $conn, string $table): bool
    {
        return Schema::connection($conn->getName())->hasTable($table);
    }

    private function note(string $step, string $message): void
    {
        $this->report[] = [$step, $message];
        $this->line("{$step}: {$message}");
    }
}
