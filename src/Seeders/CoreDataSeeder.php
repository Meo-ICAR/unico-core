<?php

namespace Unico\Core\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ricarica i dati di base del core da `database/seeders/data/<tabella>.json`, in modo da poter
 * ricostruire il database dopo un reset (`php artisan db:seed --class="Unico\Core\Seeders\CoreDataSeeder"`).
 * I file si rigenerano dal database con `php artisan unico-core:seed-export`.
 *
 * Contiene solo configurazione e anagrafiche non personali. Restano fuori, e si ripristinano dal dump del database:
 * fornitori e clienti (persone fisiche e istituti), sedi, pratiche e provvigioni, utenti, dipendenti, indirizzi,
 * documenti e file, caselle di posta e le credenziali delle aziende.
 * Ripetibile: ogni riga si inserisce o si aggiorna per chiave.
 */
class CoreDataSeeder extends Seeder
{
    /**
     * Tabelle nell'ordine di caricamento (prima quelle da cui dipendono le altre).
     *
     * @var list<string>
     */
    public const TABLES = [
        'companies',
        'organizations',
        'address_types',
        'company_roles',
        'employee_types',
        'business_functions',
        'resources',
        'email_templates',
        'tipoprodotto',
        'tipoprodotto_sub',
        'pratica_stati',
        'document_types',
        'tasks',
        'document_requirements',
        'pdf_modules',
        'pdf_module_fields',
    ];

    /**
     * Colonne che non si esportano mai: credenziali e chiavi.
     *
     * @var array<string, list<string>>
     */
    public const EXCLUDED_COLUMNS = [
        'companies' => ['istruttoria_passkey', 'preventivatore_passkey', 'db_password'],
    ];

    public static function path(string $table): string
    {
        return __DIR__.'/../../database/seeders/data/'.$table.'.json';
    }

    public function run(): void
    {
        $this->load(self::TABLES);
    }

    /**
     * Carica le tabelle indicate nell'ordine dato. I riferimenti fra tabelle (fornitori e sedi si puntano a vicenda)
     * non vengono verificati durante il caricamento.
     *
     * @param  list<string>  $tables
     */
    protected function load(array $tables): void
    {
        $db = DB::connection(config('unico-core.connection'));

        $db->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($tables as $table) {
                $file = self::path($table);

                if (! is_file($file)) {
                    continue;
                }

                $rows = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

                // Le colonne tolte dopo l'esportazione dei dati non si caricano.
                $columns = array_flip($db->getSchemaBuilder()->getColumnListing($table));
                $rows = array_map(fn (array $row): array => array_intersect_key($row, $columns), $rows);

                foreach (array_chunk(array_filter($rows), 200) as $chunk) {
                    $db->table($table)->upsert($chunk, ['id'], array_keys($chunk[0]));
                }

                $this->command?->info(sprintf('%s: %d righe', $table, count($rows)));
            }
        } finally {
            $db->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
