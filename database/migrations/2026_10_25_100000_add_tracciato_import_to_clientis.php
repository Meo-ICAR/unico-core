<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** `clientis.tracciato_import`: le intestazioni e le colonne del file con cui la banca invia gli stati delle pratiche, per riconoscerla e leggerlo subito al prossimo import. */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (! Schema::hasColumn('clientis', 'tracciato_import')) {
            Schema::table('clientis', function (Blueprint $table) {
                $table->json('tracciato_import')->nullable()->comment('Tracciato del file degli stati: intestazioni e colonne (codice, nominativo, prodotto, stato)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clientis', 'tracciato_import')) {
            Schema::table('clientis', fn (Blueprint $table) => $table->dropColumn('tracciato_import'));
        }
    }
};
