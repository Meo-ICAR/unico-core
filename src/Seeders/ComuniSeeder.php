<?php

namespace Unico\Core\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carica l'elenco dei comuni italiani da `database/data/codici-catastali.json` (codice catastale => «Nome (SIGLA)», fonte ISTAT).
 * Ripetibile: ogni comune si inserisce o si aggiorna per codice catastale.
 */
class ComuniSeeder extends Seeder
{
    public static function path(): string
    {
        return __DIR__.'/../../database/data/codici-catastali.json';
    }

    public function run(): void
    {
        $places = json_decode((string) file_get_contents(self::path()), true);

        if (! is_array($places)) {
            throw new RuntimeException('Elenco dei comuni non leggibile: '.self::path());
        }

        $rows = [];

        foreach ($places as $code => $label) {
            if (preg_match('/^(.+) \(([A-Z]{2})\)$/u', (string) $label, $match) !== 1) {
                continue;
            }

            $rows[] = ['cadastral_code' => (string) $code, 'name' => $match[1], 'province' => $match[2]];
        }

        $connection = DB::connection(config('unico-core.connection'));

        foreach (array_chunk($rows, 500) as $chunk) {
            $connection->table('comuni')->upsert($chunk, ['cadastral_code'], ['name', 'province']);
        }
    }
}
