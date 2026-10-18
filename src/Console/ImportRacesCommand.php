<?php

namespace Unico\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;
use Unico\Core\Import\RacesHistoricalImporter;

/**
 * Copia lo storico del cliente Races da proforma_races e unicooam_races.
 * Dry-run di default; --execute scrive. Le colonne rinominate stanno in RacesImportCatalog.
 */
class ImportRacesCommand extends Command
{
    protected $signature = 'unico-core:import-races
                            {--execute : Scrive sui database di destinazione; di default è un dry-run}
                            {--oam= : Database sorgente OAM (default config unico-core.import.races_oam)}
                            {--proforma= : Database sorgente Proforma (default config unico-core.import.races_proforma)}
                            {--dest-oam= : Database destinazione unicooam (default config unico-core.import.dest_oam)}
                            {--dest-proforma= : Database destinazione proforma (default config unico-core.import.dest_proforma)}
                            {--company= : Id della company sul core, se già noto}
                            {--name=Races : Frammento del nome con cui cercare la company}';

    protected $description = 'Importa lo storico Races (anagrafiche nel core, provvigioni in proforma, OAM in unicooam)';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $coreName = (string) config('unico-core.connection');

        try {
            $connections = [
                'core' => DB::connection($coreName),
                'proforma_src' => $this->open($this->optionDatabase('proforma', 'unico-core.import.races_proforma')),
                'oam_src' => $this->open($this->optionDatabase('oam', 'unico-core.import.races_oam')),
                'proforma' => $this->open($this->optionDatabase('dest-proforma', 'unico-core.import.dest_proforma')),
                'oam' => $this->open($this->optionDatabase('dest-oam', 'unico-core.import.dest_oam')),
            ];
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($execute
            ? 'Scrittura sui database di destinazione'
            : 'Dry-run: nessuna scrittura');

        $company = $this->option('company');
        $report = (new RacesHistoricalImporter(
            $connections,
            $execute,
            (string) $this->option('name'),
            filled($company) ? (int) $company : null,
        ))->run();

        foreach ($report['companies'] as $key => $companyRow) {
            $this->line("company {$key}: {$companyRow['name']} id {$companyRow['id']} ({$companyRow['action']})");
        }

        $this->table(
            ['Tabella', 'Letti', 'Inseriti', 'Aggiornati', 'Saltati', 'Falliti', 'Nota'],
            array_map(fn (array $row) => [
                $row['label'], $row['read'], $row['inserted'], $row['updated'], $row['skipped'], $row['failed'], $row['note'] ?? '',
            ], $report['tables']),
        );

        if ($report['unknown'] !== []) {
            $this->warn('Valori enum o lookup non previsti (non scritti, non scartati in silenzio):');
            foreach ($report['unknown'] as $value => $count) {
                $this->line("  {$value} ({$count})");
            }
        }
        foreach ($report['warnings'] as $warning) {
            $this->warn($warning);
        }
        foreach ($report['failures'] as $failure) {
            $this->error($failure);
        }

        $this->newLine();
        $this->info('Tabelle non importate');
        $this->table(
            ['Tabella', 'Motivo'],
            array_map(fn (array $row) => [$row['table'], $row['why']], $report['skipped']),
        );

        if (! $execute) {
            $this->comment('Per scrivere: php artisan unico-core:import-races --execute');
        }

        return self::SUCCESS;
    }

    private function optionDatabase(string $option, string $configKey): string
    {
        $value = $this->option($option);
        $database = filled($value) ? (string) $value : (string) config($configKey);
        if (! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new \RuntimeException("Nome database non valido per {$option}");
        }

        return $database;
    }

    private function open(string $database): Connection
    {
        $core = (string) config('unico-core.connection');
        $base = config("database.connections.{$core}");
        if (! is_array($base)) {
            throw new \RuntimeException("Manca la connessione {$core} in config/database.php");
        }

        $name = 'races_'.$database;
        config(["database.connections.{$name}" => array_merge($base, ['database' => $database])]);
        DB::purge($name);
        $connection = DB::connection($name);
        $connection->getPdo();

        return $connection;
    }
}
