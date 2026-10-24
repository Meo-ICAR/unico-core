<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Connessione del database core, configurabile in config/unico-core.php.
     */
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    /**
     * Campagna da cui arriva il lead della pratica (testo libero, facoltativo).
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasTable('pratiches') || $schema->hasColumn('pratiches', 'campagna_lead')) {
            return;
        }

        $schema->table('pratiches', function (Blueprint $table) {
            $table->string('campagna_lead')->nullable()->after('codice_pratica_banca')->comment('Campagna da cui arriva il lead della pratica (testo libero)');
        });
    }

    public function down(): void
    {
        // La colonna resta: contiene dati inseriti dagli utenti.
    }
};
