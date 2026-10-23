<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Esecuzioni dell'import storico Races: l'import incrementale riparte dall'ultima riuscita (`unico-core:import-races --incremental`). */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (Schema::hasTable('races_import_runs')) {
            return;
        }

        Schema::create('races_import_runs', function (Blueprint $table) {
            $table->id()->comment('ID univoco dell\'esecuzione');
            $table->timestamp('started_at')->comment('Inizio dell\'import: da qui l\'incrementale legge le righe modificate');
            $table->timestamp('finished_at')->nullable()->comment('Fine dell\'import; nullo se è ancora in corso o si è interrotto');
            $table->unsignedInteger('failed')->default(0)->comment('Righe fallite: un import con errori non fa da punto di partenza');
            $table->comment('Esecuzioni dell\'import storico Races.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('races_import_runs');
    }
};
