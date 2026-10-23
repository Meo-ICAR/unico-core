<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Valori storici di `documents.status` (inglese o con la maiuscola) e il valore di DocumentStatus che li sostituisce.
     *
     * @var array<string, string>
     */
    private const LEGACY_VALUES = [
        'uploaded' => 'caricato',
        'Approvato' => 'approvato',
        'approved' => 'approvato',
        'pending' => 'richiesto',
    ];

    /**
     * Connessione del database core, configurabile in config/unico-core.php.
     */
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (! Schema::connection($this->getConnection())->hasTable('documents')) {
            return;
        }

        foreach (self::LEGACY_VALUES as $legacy => $current) {
            DB::connection($this->getConnection())->table('documents')->whereRaw('BINARY status = ?', [$legacy])->update(['status' => $current]);
        }
    }

    public function down(): void {}
};
