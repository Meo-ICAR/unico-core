<?php

namespace Unico\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Unico\Core\Seeders\CoreDataSeeder;

class SeedExportCommand extends Command
{
    protected $signature = 'unico-core:seed-export {--table=* : Solo queste tabelle}';

    protected $description = 'Scrive i dati di base del core in database/seeders/data, da ricaricare con CoreDataSeeder';

    public function handle(): int
    {
        $db = DB::connection(config('unico-core.connection'));
        $tables = $this->option('table') ?: CoreDataSeeder::TABLES;

        foreach ($tables as $table) {
            if (! in_array($table, CoreDataSeeder::TABLES, true)) {
                $this->error("La tabella {$table} non è tra quelle esportabili.");

                return self::FAILURE;
            }

            $excluded = CoreDataSeeder::EXCLUDED_COLUMNS[$table] ?? [];
            $rows = $db->table($table)->orderBy('id')->get()
                ->map(fn ($row) => array_diff_key((array) $row, array_flip($excluded)))
                ->all();

            $lines = array_map(fn (array $row): string => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $rows);
            file_put_contents(CoreDataSeeder::path($table), "[\n".implode(",\n", $lines)."\n]\n");

            $this->info(sprintf('%s: %d righe', $table, count($rows)));
        }

        return self::SUCCESS;
    }
}
