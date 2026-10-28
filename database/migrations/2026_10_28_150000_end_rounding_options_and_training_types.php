<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tipi documento della formazione annuale (aggiornamento OAM 15h e 30h, IVASS 30h): validità di 12 mesi approssimata a fine anno,
     * con preavviso 60 giorni prima e poi ai 15, 7 e 0 giorni.
     */
    private const TIPI = [
        'Formazione 15h aggiornamento OAM ( se IVASS )',
        'Formazione 30h aggiornamento OAM',
        'Formazione 30h aggiornamento IVASS',
    ];

    /**
     * Connessione del database core, configurabile in config/unico-core.php.
     */
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    /**
     * `is_end_month` diventa l'opzione di approssimazione della scadenza: 0 nessuna, 1 fine mese, 2 fine anno (31 dicembre). Il tipo la
     * propone e il documento la ricopia, dove si può forzare. La applicano i tipi della formazione annuale e i loro documenti già
     * caricati si riallineano.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $db = DB::connection($this->getConnection());

        if (! $schema->hasTable('document_types') || ! $schema->hasTable('documents')) {
            return;
        }

        if ($db->getDriverName() === 'mysql') {
            foreach (['document_types', 'documents'] as $tabella) {
                if ($schema->hasColumn($tabella, 'is_end_month')) {
                    $db->statement("ALTER TABLE `{$tabella}` MODIFY COLUMN `is_end_month` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Approssima la scadenza: 0 nessuna, 1 a fine mese, 2 a fine anno (31 dicembre)'");
                }
            }
        }

        $tipi = $db->table('document_types')->whereIn('name', self::TIPI);
        $ids = (clone $tipi)->pluck('id');

        (clone $tipi)->update(['duration' => 12, 'duration_unit' => 'months', 'is_end_month' => 2, 'is_monitored' => true, 'notify_days_before' => json_encode([60, 15, 7, 0])]);

        $db->table('documents')->whereIn('document_type_id', $ids)->whereNotNull('emitted_at')->orderBy('id')->each(
            fn (object $documento) => $db->table('documents')->where('id', $documento->id)->update([
                'is_end_month' => 2,
                'expires_at' => Carbon::parse($documento->emitted_at)->addMonths(12)->endOfYear()->toDateString(),
            ])
        );
    }

    public function down(): void {}
};
