<?php

namespace Unico\Core\Import;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copia i dump storici Races in ordine di dipendenza.
 * Senza execute non scrive: calcola comunque letti, inseriti, aggiornati, saltati, falliti.
 */
class RacesHistoricalImporter
{
    /** @var array<string, array<string, array<string, int|string>>> */
    private array $ids = [];

    /** @var array<string, array<string, int|string>> */
    private array $names = [];

    /** @var array<string, int|string|null> */
    private array $companyIds = [];

    /** @var list<array<string, mixed>> */
    private array $tables = [];

    /** @var array<string, int> */
    private array $unknown = [];

    /** @var list<string> */
    private array $failures = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, array<string, true>> */
    private array $seenLocal = [];

    /** @var array<string, true> */
    private array $localLoaded = [];

    /** @var array<string, array<string, mixed>> */
    private array $columnMeta = [];

    /** @param  array<string, Connection>  $connections */
    public function __construct(
        private array $connections,
        private bool $execute,
        private string $nameFragment = 'Races',
        private ?int $forcedCompanyId = null,
        private string $companyTable = 'companies',
    ) {}

    /**
     * @param  list<array<string, mixed>>|null  $catalog
     * @return array<string, mixed>
     */
    public function run(?array $catalog = null): array
    {
        $plans = $catalog ?? RacesImportCatalog::tables();
        $this->ensureCompanies($plans);

        $destinations = [];
        foreach ($plans as $plan) {
            $destinations[$plan['dest']] = $this->connections[$plan['dest']];
        }

        if ($this->execute) {
            foreach ($destinations as $connection) {
                $connection->statement('SET FOREIGN_KEY_CHECKS=0');
            }
        }

        try {
            foreach ($plans as $plan) {
                if (isset($plan['handler'])) {
                    $this->{$plan['handler']}($plan);
                } else {
                    $this->importTable($plan);
                }
            }
        } finally {
            if ($this->execute) {
                foreach ($destinations as $connection) {
                    $connection->statement('SET FOREIGN_KEY_CHECKS=1');
                }
            }
        }

        return [
            'companies' => $this->companyIds,
            'tables' => $this->tables,
            'unknown' => $this->unknown,
            'failures' => $this->failures,
            'warnings' => $this->warnings,
            'skipped' => RacesImportCatalog::skipped(),
        ];
    }

    /** @param  list<array<string, mixed>>  $plans */
    private function ensureCompanies(array $plans): void
    {
        $keys = [];
        foreach ($plans as $plan) {
            $keys[$plan['dest']] = true;
        }
        if (isset($keys['core'])) {
            $this->companyIds['core'] = $this->ensureCompany('core', null);
        }
        $coreId = $this->companyIds['core']['id'] ?? null;
        foreach (array_keys($keys) as $key) {
            if ($key === 'core') {
                continue;
            }
            $this->companyIds[$key] = $this->ensureCompany($key, is_numeric($coreId) ? (int) $coreId : null);
        }
    }

    /** @return array{id: int|string|null, name: ?string, action: string} */
    private function ensureCompany(string $destKey, ?int $cloneFromCore): array
    {
        $dest = $this->connections[$destKey];
        if (! $this->hasTable($dest, $this->companyTable)) {
            return ['id' => null, 'name' => null, 'action' => 'tabella assente'];
        }

        if ($destKey === 'core' && $this->forcedCompanyId !== null) {
            $forced = $dest->table($this->companyTable)->where('id', $this->forcedCompanyId)->first();
            if ($forced) {
                return ['id' => $forced->id, 'name' => $forced->name ?? null, 'action' => 'id indicato'];
            }
        }

        $fragment = str_replace(['%', '_'], ['\\%', '\\_'], $this->nameFragment);
        $matches = $dest->table($this->companyTable)->where('name', 'like', '%'.$fragment.'%')->orderBy('id')->get();
        if ($cloneFromCore !== null && $this->connections['core']->getDatabaseName() !== $dest->getDatabaseName()) {
            $core = $this->connections['core']->table($this->companyTable)->where('id', $cloneFromCore)->first();
            if ($core && filled($core->vat_number ?? null)) {
                $byVat = $dest->table($this->companyTable)->where('vat_number', $core->vat_number)->orderBy('id')->get();
                if ($byVat->isNotEmpty()) {
                    $matches = $byVat;
                }
            }
        }

        if ($matches->isNotEmpty()) {
            $chosen = $matches->first(fn ($row) => filled($row->vat_number ?? null)) ?? $matches->first();
            if ($matches->count() > 1) {
                $this->warnings[] = "{$dest->getDatabaseName()}: più company con «{$this->nameFragment}», usata id {$chosen->id}";
            }

            return ['id' => $chosen->id, 'name' => $chosen->name ?? null, 'action' => 'trovata'];
        }

        $payload = $this->companyPayload($dest, $cloneFromCore);
        if (! $this->execute) {
            $next = ((int) $dest->table($this->companyTable)->max('id')) + 1;

            return ['id' => $next, 'name' => $payload['name'] ?? $this->nameFragment, 'action' => 'da creare'];
        }

        $id = $dest->table($this->companyTable)->insertGetId($payload);

        return ['id' => $id, 'name' => $payload['name'] ?? null, 'action' => 'creata'];
    }

    /** @return array<string, mixed> */
    private function companyPayload(Connection $dest, ?int $cloneFromCore): array
    {
        $source = null;
        if ($cloneFromCore !== null && $this->hasTable($this->connections['core'], $this->companyTable)) {
            $source = $this->connections['core']->table($this->companyTable)->where('id', $cloneFromCore)->first();
        }

        $allowed = ['name', 'vat_number', 'vat_name', 'oam', 'oam_at', 'oam_name', 'numero_iscrizione_rui', 'company_type', 'type', 'email', 'emailsubject'];
        $columns = $this->columnNames($dest, $this->companyTable);
        $payload = [];
        foreach ($allowed as $column) {
            if (! in_array($column, $columns, true)) {
                continue;
            }
            if ($source !== null && isset($source->{$column})) {
                $payload[$column] = $source->{$column};
            }
        }
        if (! isset($payload['name']) || $payload['name'] === '') {
            $payload['name'] = $this->nameFragment;
        }
        if (in_array('company_type', $columns, true) && blank($payload['company_type'] ?? null)) {
            $payload['company_type'] = 'mediatore';
        }

        return $this->onlyKnown($payload, $columns);
    }

    /** @param  array<string, mixed>  $plan */
    private function importTable(array $plan): void
    {
        $source = $this->connections[$plan['source']];
        $dest = $this->connections[$plan['dest']];
        $from = $plan['source_table'];
        $to = $plan['dest_table'];

        if (! $this->hasTable($source, $from)) {
            $this->push($plan, 0, 0, 0, 0, 0, 'tabella assente sulla sorgente');

            return;
        }
        if (! $this->hasTable($dest, $to)) {
            $this->push($plan, 0, 0, 0, 0, 0, 'tabella assente sulla destinazione');

            return;
        }

        $destColumns = $this->columnNames($dest, $to);
        if (filled($plan['legacy'] ?? null) && ! in_array($plan['legacy'], $destColumns, true)) {
            $this->warnings[] = "{$to}: manca la colonna {$plan['legacy']}; eseguire le migration del core prima dell'import definitivo";
        }

        $pk = $this->orderColumn($source, $from, (string) ($plan['pk'] ?? 'id'));
        $skipHeavy = ['extracted_text'];
        $select = array_values(array_diff($this->columnNames($source, $from), $skipHeavy));
        $inserted = $updated = $skipped = $failed = $read = 0;
        $selfLater = [];
        $offset = 0;

        do {
            $chunk = $source->table($from)->select($select)->orderBy($pk)->offset($offset)->limit(400)->get();
            $offset += 400;
            foreach ($chunk as $row) {
                $read++;
                $src = (array) $row;
                $sourceId = array_key_exists('id', $src) ? (string) $src['id'] : null;
                try {
                    $prepared = $this->prepare($plan, $src);
                    $outcome = $this->writeRow($plan, $prepared, $destColumns, $sourceId, $selfLater);
                    match ($outcome) {
                        'inserted' => $inserted++,
                        'updated' => $updated++,
                        'skipped' => $skipped++,
                        default => $failed++,
                    };
                } catch (Throwable $e) {
                    $failed++;
                    $this->rememberFailure($to, $sourceId, $e);
                }
            }
        } while ($chunk->count() === 400);

        $this->applySelfFk($plan, $selfLater);
        $this->push($plan, $read, $inserted, $updated, $skipped, $failed);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $src
     * @return array<string, mixed>
     */
    private function prepare(array $plan, array $src): array
    {
        if (filled($plan['prepare'] ?? null)) {
            $src = $this->{$plan['prepare']}($src);
        }
        foreach ($plan['renames'] ?? [] as $from => $to) {
            if (array_key_exists($from, $src) && ! array_key_exists($to, $src)) {
                $src[$to] = $src[$from];
            }
            unset($src[$from]);
        }

        return $src;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $src
     * @param  list<string>  $destColumns
     * @param  list<array{0: int|string, 1: string, 2: mixed}>  $selfLater
     */
    private function writeRow(array $plan, array $src, array $destColumns, ?string $sourceId, array &$selfLater): string
    {
        $dest = $this->connections[$plan['dest']];
        $to = $plan['dest_table'];
        $group = $this->group($plan['source']);
        $pk = (string) ($plan['pk'] ?? 'id');
        $payload = $this->onlyKnown($src, $destColumns);

        if (filled($plan['legacy'] ?? null) && $sourceId !== null && in_array($plan['legacy'], $destColumns, true)) {
            $payload[$plan['legacy']] = $sourceId;
        }
        if (($plan['company'] ?? false) && in_array('company_id', $destColumns, true)) {
            $payload['company_id'] = $this->companyIds[$plan['dest']]['id'] ?? null;
        }

        if (is_array($plan['morph'] ?? null)) {
            [$typeCol, $idCol] = $plan['morph'];
            if (array_key_exists($typeCol, $src)) {
                $morphTable = $this->morphTable((string) $src[$typeCol]);
                if ($morphTable === 'companies') {
                    $payload[$idCol] = $this->companyIds[$plan['dest']]['id'] ?? null;
                } elseif ($morphTable !== null && array_key_exists($idCol, $src)) {
                    $mapped = $this->resolveId($group, $morphTable, $src[$idCol]);
                    if ($mapped !== null) {
                        $payload[$idCol] = $mapped;
                    } elseif (($plan['numeric_morph'] ?? false) && $this->isNumericColumn($dest, $to, $idCol) && ! is_numeric($src[$idCol])) {
                        return 'skipped';
                    }
                }
            }
        }

        foreach ($plan['fk'] ?? [] as $column => $table) {
            if (in_array($column, $plan['self_fk'] ?? [], true) || ! in_array($column, $destColumns, true)) {
                continue;
            }
            $raw = $payload[$column] ?? $src[$column] ?? null;
            $payload[$column] = ($raw === null || $raw === '') ? null : $this->resolveId($group, $table, $raw);
        }

        foreach ($plan['local_fk'] ?? [] as $column => $table) {
            if (! array_key_exists($column, $payload) || $payload[$column] === null || $payload[$column] === '') {
                continue;
            }
            $looks = $this->localPk($table);
            if (! $this->localExists($plan['dest'], $table, $looks, $payload[$column])) {
                $this->unknown[$to.'.'.$column.'='.$payload[$column]] = ($this->unknown[$to.'.'.$column.'='.$payload[$column]] ?? 0) + 1;
                $payload[$column] = null;
            }
        }

        $payload = $this->sanitize($dest, $to, $payload, $destColumns);

        $selfValues = [];
        foreach ($plan['self_fk'] ?? [] as $column) {
            if (array_key_exists($column, $src) && $src[$column] !== null && $src[$column] !== '') {
                $selfValues[$column] = $src[$column];
            }
            if (in_array($column, $destColumns, true)) {
                $payload[$column] = null;
            }
        }

        $existing = $this->findExisting($dest, $to, $plan, $payload, $sourceId);
        $preserve = (bool) ($plan['preserve_id'] ?? false);
        $writing = $payload;
        unset($writing[$pk]);

        if ($existing) {
            foreach ($plan['preserve_on_update'] ?? [] as $column) {
                unset($writing[$column]);
            }
            $destId = $existing->{$pk};
            if ($this->execute && $writing !== []) {
                $dest->table($to)->where($pk, $destId)->update($writing);
            }
            $status = 'updated';
        } else {
            foreach ($plan['defaults'] ?? [] as $column => $value) {
                if (! array_key_exists($column, $writing) || $writing[$column] === null || $writing[$column] === '') {
                    if (in_array($column, $destColumns, true)) {
                        $writing[$column] = $value;
                    }
                }
            }
            $keepId = $preserve && $pk === 'id' && $sourceId !== null && $sourceId !== '' && ! $this->idTaken($dest, $to, $sourceId);
            if ($keepId) {
                $writing['id'] = is_numeric($sourceId) ? (int) $sourceId : $sourceId;
            }
            if ($this->execute) {
                if ($keepId || $pk !== 'id') {
                    if ($pk !== 'id' && array_key_exists($pk, $payload)) {
                        $writing[$pk] = $payload[$pk];
                    }
                    $dest->table($to)->insert($writing);
                    $destId = $writing['id'] ?? $writing[$pk] ?? $sourceId;
                } else {
                    $destId = $dest->table($to)->insertGetId($writing);
                }
            } else {
                $destId = $keepId || $pk !== 'id'
                    ? ($writing['id'] ?? $payload[$pk] ?? $sourceId)
                    : (((int) $dest->table($to)->max('id')) + 1 + $this->pretendBump($plan['dest'], $to));
            }
            $status = 'inserted';
        }

        if ($sourceId !== null && $sourceId !== '' && $destId !== null) {
            $this->ids[$group][$to][$sourceId] = $destId;
            if (str_contains($sourceId, '-')) {
                $this->ids['*'][$to][$sourceId] = $destId;
            }
        }
        $this->rememberName($to, $payload, $destId);
        if ($destId !== null) {
            $this->rememberLocal($plan['dest'], $to, $pk, $destId);
            foreach ($selfValues as $column => $value) {
                $selfLater[] = [$destId, $column, $value];
            }
        }

        return $status;
    }

    /** @param  list<array{0: int|string, 1: string, 2: mixed}>  $later */
    private function applySelfFk(array $plan, array $later): void
    {
        if ($later === [] || ! $this->execute) {
            return;
        }
        $dest = $this->connections[$plan['dest']];
        $to = $plan['dest_table'];
        $group = $this->group($plan['source']);
        $pk = (string) ($plan['pk'] ?? 'id');
        foreach ($later as [$destId, $column, $raw]) {
            $mapped = $this->resolveId($group, $to, $raw);
            if ($mapped === null) {
                continue;
            }
            $dest->table($to)->where($pk, $destId)->update([$column => $mapped]);
        }
    }

    /** @param  array<string, mixed>  $plan */
    private function importPraticaStati(array $plan): void
    {
        $source = $this->connections[$plan['source']];
        $dest = $this->connections[$plan['dest']];
        if (! $this->hasTable($source, 'pratiches') || ! $this->hasTable($dest, 'pratica_stati')) {
            $this->push($plan, 0, 0, 0, 0, 0, 'tabella assente');

            return;
        }

        $flags = [];
        if ($this->hasTable($source, 'pratiches_statos')) {
            foreach ($source->table('pratiches_statos')->get() as $row) {
                $flags[Str::lower(trim((string) $row->stato_pratica))] = $row;
            }
        }

        $pairs = $source->table('pratiches')->select('stato_pratica', 'tipo_prodotto')->whereNotNull('stato_pratica')->where('stato_pratica', '!=', '')->distinct()->get();
        $inserted = $updated = 0;
        $seen = [];
        $ordine = (int) $dest->table('pratica_stati')->max('ordine');

        foreach ($pairs as $pair) {
            $codice = trim((string) $pair->stato_pratica);
            if ($codice === '' || isset($seen[Str::lower($codice)])) {
                continue;
            }
            $seen[Str::lower($codice)] = true;
            $flag = $flags[Str::lower($codice)] ?? null;
            $ordine++;
            $payload = [
                'codice' => $codice,
                'tipo_finanziamento' => trim((string) ($pair->tipo_prodotto ?? '')) ?: null,
                'name' => $codice,
                'ordine' => $ordine,
                'is_rejected' => (bool) ($flag->isrejected ?? false),
                'is_working' => $flag ? (bool) $flag->isworking : true,
                'is_estingued' => (bool) ($flag->isestingued ?? false),
                'updated_at' => now(),
            ];
            $payload = $this->onlyKnown($payload, $this->columnNames($dest, 'pratica_stati'));
            $existing = $dest->table('pratica_stati')->whereRaw('LOWER(codice) = ?', [Str::lower($codice)])->first();
            if ($existing) {
                $updated++;
                $id = $existing->id;
                if ($this->execute) {
                    $dest->table('pratica_stati')->where('id', $id)->update($payload);
                }
            } else {
                $inserted++;
                $payload['created_at'] = now();
                $id = $this->execute
                    ? $dest->table('pratica_stati')->insertGetId($payload)
                    : (((int) $dest->table('pratica_stati')->max('id')) + $inserted);
            }
            $this->names['pratica_stati'][Str::lower($codice)] = $id;
            $this->rememberLocal($plan['dest'], 'pratica_stati', 'id', $id);
        }

        $this->push($plan, $pairs->count(), $inserted, $updated, 0, 0);
    }

    /** @param  array<string, mixed>  $plan */
    private function importPratiches(array $plan): void
    {
        $source = $this->connections[$plan['source']];
        $dest = $this->connections[$plan['dest']];
        if (! $this->hasTable($source, 'pratiches') || ! $this->hasTable($dest, 'pratiches')) {
            $this->push($plan, 0, 0, 0, 0, 0, 'tabella assente');

            return;
        }

        $companyId = $this->companyIds[$plan['dest']]['id'] ?? null;
        $columns = $this->columnNames($dest, 'pratiches');
        $rows = $source->table('pratiches')->orderBy('id')->get();
        $inserted = $updated = $failed = 0;
        $group = $this->group($plan['source']);

        foreach ($rows as $row) {
            $src = (array) $row;
            $sourceId = (string) ($src['id'] ?? $src['codice_pratica'] ?? '');
            try {
                $payload = $this->onlyKnown($src, $columns);
                unset($payload['id']);
                $payload['company_id'] = $companyId;
                $payload['codice_pratica'] = filled($src['codice_pratica'] ?? null) ? $src['codice_pratica'] : $sourceId;
                $tipo = Str::lower(trim((string) ($src['tipo_prodotto'] ?? '')));
                $stato = Str::lower(trim((string) ($src['stato_pratica'] ?? '')));
                $payload['tipoprodotto_id'] = $tipo === '' ? null : ($this->lookupName('tipoprodotto', $tipo, $dest) ?? null);
                $payload['pratica_stato_id'] = $stato === '' ? null : ($this->names['pratica_stati'][$stato] ?? $this->lookupName('pratica_stati', $stato, $dest, 'codice'));
                $piva = Str::lower(trim((string) ($src['partita_iva_agente'] ?? '')));
                $payload['fornitori_id'] = $piva === '' ? null : $this->findByColumn($dest, 'fornitoris', 'piva', $piva);
                $banca = Str::lower(trim((string) ($src['denominazione_banca'] ?? '')));
                $payload['clienti_id'] = $banca === '' ? null : $this->findByColumn($dest, 'clientis', 'name', $banca);
                if (in_array('upload_at', $columns, true) && blank($payload['upload_at'] ?? null)) {
                    $payload['upload_at'] = now();
                }
                $payload = $this->sanitize($dest, 'pratiches', $this->onlyKnown($payload, $columns), $columns);
                $existing = $dest->table('pratiches')->where('codice_pratica', $payload['codice_pratica'])->first();
                if ($existing) {
                    $updated++;
                    $destId = $existing->id;
                    if ($this->execute) {
                        $dest->table('pratiches')->where('id', $destId)->update($payload);
                    }
                } else {
                    $inserted++;
                    $destId = $this->execute
                        ? $dest->table('pratiches')->insertGetId($payload)
                        : (((int) $dest->table('pratiches')->max('id')) + $inserted);
                }
                if ($sourceId !== '') {
                    $this->ids[$group]['pratiches'][$sourceId] = $destId;
                    $this->ids[$group]['pratiches'][(string) $payload['codice_pratica']] = $destId;
                }
                $this->rememberLocal($plan['dest'], 'pratiches', 'id', $destId);
            } catch (Throwable $e) {
                $failed++;
                $this->rememberFailure('pratiches', $sourceId, $e);
            }
        }

        $this->push($plan, $rows->count(), $inserted, $updated, 0, $failed);
    }

    /** @param  array<string, mixed>  $row */
    private function prepareFornitore(array $row): array
    {
        if (blank($row['branch_id'] ?? null) && filled($row['company_branch_id'] ?? null)) {
            $row['branch_id'] = $row['company_branch_id'];
        }
        unset($row['company_branch_id']);

        return $row;
    }

    /** @param  array<string, mixed>  $row */
    private function prepareDocumentType(array $row): array
    {
        $map = [
            'is_endMonth' => 'is_end_month',
            'is_AiAbstract' => 'is_ai_abstract',
            'is_AiCheck' => 'is_ai_check',
            'AiPattern' => 'ai_pattern',
        ];
        foreach ($map as $old => $new) {
            if (array_key_exists($old, $row) && ! array_key_exists($new, $row)) {
                $row[$new] = $row[$old];
            }
            unset($row[$old]);
        }
        if (array_key_exists('expire_days_before', $row) && ! array_key_exists('notify_days_before', $row) && $row['expire_days_before'] !== null) {
            $row['notify_days_before'] = json_encode([(int) $row['expire_days_before']]);
        }
        unset($row['expire_days_before']);

        return $row;
    }

    /** @param  array<string, mixed>  $row */
    private function prepareDocument(array $row): array
    {
        if (array_key_exists('is_endMonth', $row) && ! array_key_exists('is_end_month', $row)) {
            $row['is_end_month'] = $row['is_endMonth'];
        }
        unset($row['is_endMonth'], $row['extracted_text'], $row['is_signed']);

        return $row;
    }

    /** @param  array<string, mixed>  $row */
    private function prepareMailAccount(array $row): array
    {
        $map = [
            'incoming_protocol' => 'protocol',
            'incoming_host' => 'imap_host',
            'incoming_port' => 'imap_port',
            'incoming_username' => 'imap_username',
            'incoming_password' => 'imap_password',
            'incoming_encryption' => 'imap_encryption',
        ];
        foreach ($map as $old => $new) {
            if (array_key_exists($old, $row) && ! array_key_exists($new, $row)) {
                $row[$new] = $row[$old];
            }
            unset($row[$old]);
        }

        return $row;
    }

    /** @param  array<string, mixed>  $row */
    private function prepareProvvigione(array $row): array
    {
        if (blank($row['pratica_id'] ?? null) && filled($row['id_pratica'] ?? null)) {
            $row['pratica_id'] = $row['id_pratica'];
        }

        return $row;
    }

    private function resolveId(string $group, string $table, mixed $value): int|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        $key = (string) $value;
        if (isset($this->ids[$group][$table][$key])) {
            return $this->ids[$group][$table][$key];
        }
        if (isset($this->ids['*'][$table][$key])) {
            return $this->ids['*'][$table][$key];
        }

        return null;
    }

    private function group(string $sourceKey): string
    {
        return str_starts_with($sourceKey, 'oam') ? 'oam' : 'proforma';
    }

    private function morphTable(string $type): ?string
    {
        $base = Str::lower(basename(str_replace('\\', '/', $type)));

        return match ($base) {
            'client', 'clients' => 'clients',
            'clienti', 'cliente', 'clientis' => 'clientis',
            'fornitore', 'fornitori', 'fornitoris' => 'fornitoris',
            'employee', 'employees' => 'employees',
            'company', 'companies' => 'companies',
            'document', 'documents' => 'documents',
            'documenttype', 'document_type', 'document_types' => 'document_types',
            'user', 'users' => 'users',
            'audit', 'audits' => 'audits',
            'branch', 'branches' => 'branches',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $payload
     */
    private function findExisting(Connection $dest, string $table, array $plan, array $payload, ?string $sourceId): ?object
    {
        foreach ($plan['match'] ?? [] as $keys) {
            if ($keys === ['id']) {
                continue;
            }
            $query = $dest->table($table);
            $usable = true;
            foreach ($keys as $key) {
                if (! array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
                    $usable = false;
                    break;
                }
                $query->where($key, $payload[$key]);
            }
            if (! $usable) {
                continue;
            }
            $hit = $query->first();
            if ($hit) {
                return $hit;
            }
        }

        if (($plan['preserve_id'] ?? false) && $sourceId !== null && $sourceId !== '' && $this->idTaken($dest, $table, $sourceId)) {
            $byId = $dest->table($table)->where('id', is_numeric($sourceId) ? (int) $sourceId : $sourceId)->first();
            if ($byId && $this->sameNaturalKey($byId, $plan, $payload)) {
                return $byId;
            }
        }

        return null;
    }

    /**
     * Lo stesso id si riusa solo se le chiavi naturali compilate non contraddicono la riga già presente.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $payload
     */
    private function sameNaturalKey(object $row, array $plan, array $payload): bool
    {
        foreach ($plan['match'] ?? [] as $keys) {
            if ($keys === ['id']) {
                continue;
            }
            foreach ($keys as $key) {
                $incoming = $payload[$key] ?? null;
                $current = $row->{$key} ?? null;
                if ($incoming === null || $incoming === '' || $current === null || $current === '') {
                    continue;
                }
                if ((string) $current !== (string) $incoming) {
                    return false;
                }
            }
        }

        return true;
    }

    private function idTaken(Connection $dest, string $table, string $id): bool
    {
        return $dest->table($table)->where('id', is_numeric($id) ? (int) $id : $id)->exists();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function sanitize(Connection $dest, string $table, array $payload, array $columns): array
    {
        $meta = $this->meta($dest, $table);
        foreach ($payload as $column => $value) {
            if (! in_array($column, $columns, true)) {
                unset($payload[$column]);

                continue;
            }
            if (is_string($value) && in_array($value, ['0000-00-00', '0000-00-00 00:00:00'], true)) {
                $payload[$column] = null;
                $value = null;
            }
            $enum = $meta[$column]['enum'] ?? null;
            if ($enum !== null && $value !== null && $value !== '') {
                $canonical = $this->enumCanonical($enum, (string) $value);
                if ($canonical === null) {
                    $this->unknown[$table.'.'.$column.'='.$value] = ($this->unknown[$table.'.'.$column.'='.$value] ?? 0) + 1;
                    $payload[$column] = null;
                } else {
                    $payload[$column] = $canonical;
                }
            }
            if (is_array($payload[$column] ?? null)) {
                $payload[$column] = json_encode($payload[$column]);
            }
        }

        return $payload;
    }

    /** @param  list<string>  $enum */
    private function enumCanonical(array $enum, string $value): ?string
    {
        if (in_array($value, $enum, true)) {
            return $value;
        }
        foreach ($enum as $allowed) {
            if (Str::lower($allowed) === Str::lower($value)) {
                return $allowed;
            }
        }

        return null;
    }

    /** @return array<string, array{enum: ?array, numeric: bool}> */
    private function meta(Connection $dest, string $table): array
    {
        $key = $dest->getName().'|'.$dest->getDatabaseName().'|'.$table;
        if (isset($this->columnMeta[$key])) {
            return $this->columnMeta[$key];
        }
        $rows = $dest->select(
            'select COLUMN_NAME as name, COLUMN_TYPE as type, DATA_TYPE as data_type from information_schema.COLUMNS where TABLE_SCHEMA = ? and TABLE_NAME = ?',
            [$dest->getDatabaseName(), $table]
        );
        $meta = [];
        foreach ($rows as $row) {
            $enum = null;
            if ($row->data_type === 'enum' && preg_match_all("/'((?:[^']|'')*)'/", (string) $row->type, $matches)) {
                $enum = array_map(fn ($value) => str_replace("''", "'", $value), $matches[1]);
            }
            $meta[$row->name] = [
                'enum' => $enum,
                'numeric' => in_array($row->data_type, ['bigint', 'int', 'integer', 'mediumint', 'smallint', 'tinyint'], true),
            ];
        }

        return $this->columnMeta[$key] = $meta;
    }

    private function isNumericColumn(Connection $dest, string $table, string $column): bool
    {
        return (bool) ($this->meta($dest, $table)[$column]['numeric'] ?? false);
    }

    private function localPk(string $table): string
    {
        foreach (RacesImportCatalog::tables() as $plan) {
            if ($plan['dest_table'] === $table && ($plan['pk'] ?? 'id') !== 'id') {
                return (string) $plan['pk'];
            }
        }

        return 'id';
    }

    private function localExists(string $destKey, string $table, string $column, mixed $value): bool
    {
        $cache = $destKey.'|'.$table.'|'.$column;
        if (! isset($this->localLoaded[$cache])) {
            $this->localLoaded[$cache] = true;
            $dest = $this->connections[$destKey];
            if ($this->hasTable($dest, $table) && in_array($column, $this->columnNames($dest, $table), true)) {
                foreach ($dest->table($table)->pluck($column) as $existing) {
                    if ($existing !== null) {
                        $this->seenLocal[$cache][(string) $existing] = true;
                    }
                }
            }
        }

        return isset($this->seenLocal[$cache][(string) $value]);
    }

    private function rememberLocal(string $destKey, string $table, string $column, mixed $id): void
    {
        if ($id === null) {
            return;
        }
        $this->seenLocal[$destKey.'|'.$table.'|'.$column][(string) $id] = true;
    }

    /** @param  array<string, mixed>  $payload */
    private function rememberName(string $table, array $payload, mixed $id): void
    {
        if ($id === null) {
            return;
        }
        foreach (['tipo_prodotto', 'name', 'codice'] as $column) {
            if (filled($payload[$column] ?? null)) {
                $this->names[$table][Str::lower((string) $payload[$column])] = $id;
            }
        }
    }

    private function lookupName(string $table, string $lower, Connection $dest, string $column = 'tipo_prodotto'): int|string|null
    {
        if (isset($this->names[$table][$lower])) {
            return $this->names[$table][$lower];
        }
        if (! $this->hasTable($dest, $table) || ! in_array($column, $this->columnNames($dest, $table), true)) {
            return null;
        }
        $hit = $dest->table($table)->whereRaw('LOWER('.$column.') = ?', [$lower])->first();

        return $hit?->id;
    }

    private function findByColumn(Connection $dest, string $table, string $column, string $lower): int|string|null
    {
        if (! $this->hasTable($dest, $table)) {
            return null;
        }
        $hit = $dest->table($table)->whereRaw('LOWER('.$column.') = ?', [$lower])->first();

        return $hit?->id;
    }

    /** @param  array<string, mixed>  $payload */
    private function onlyKnown(array $payload, array $columns): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, $columns, true)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function columnNames(Connection $connection, string $table): array
    {
        return $connection->getSchemaBuilder()->getColumnListing($table);
    }

    private function hasTable(Connection $connection, string $table): bool
    {
        return $connection->getSchemaBuilder()->hasTable($table);
    }

    private function orderColumn(Connection $connection, string $table, string $preferred): string
    {
        $columns = $this->columnNames($connection, $table);

        return in_array($preferred, $columns, true) ? $preferred : ($columns[0] ?? $preferred);
    }

    private function pretendBump(string $destKey, string $table): int
    {
        $key = $destKey.'|'.$table.'|pretend';
        $this->seenLocal[$key]['n'] = ((int) ($this->seenLocal[$key]['n'] ?? 0)) + 1;

        return (int) $this->seenLocal[$key]['n'];
    }

    /** @param  array<string, mixed>  $plan */
    private function push(array $plan, int $read, int $inserted, int $updated, int $skipped, int $failed, ?string $note = null): void
    {
        $this->tables[] = [
            'label' => $plan['source_table'].' → '.$plan['dest'].'.'.$plan['dest_table'],
            'read' => $read,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'note' => $note,
        ];
    }

    private function rememberFailure(string $table, ?string $id, Throwable $e): void
    {
        if (count($this->failures) >= 30) {
            return;
        }
        $message = $e->getMessage();
        if (stripos($message, 'password') !== false) {
            $message = 'errore SQL (dettaglio omesso)';
        }
        $this->failures[] = $table.' id='.($id ?? '?').': '.Str::limit($message, 240);
    }
}
