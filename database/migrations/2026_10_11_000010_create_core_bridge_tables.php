<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lanci di comandi richiesti da un sistema esterno (unicobpm, app di terzi) tramite il bridge API. Migration scritta a mano.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        Schema::create('command_runs', function (Blueprint $table) {
            $table->id()->comment('ID del lancio: è quello che il chiamante riceve e usa per leggerne l\'esito');
            $table->string('command')->comment('Comando Artisan lanciato (solo quelli consentiti dalla whitelist del bridge)');
            $table->json('options')->nullable()->comment('Opzioni passate al comando');
            $table->string('status', 20)->default('queued')->comment('queued, running, succeeded, failed, skipped (un\'esecuzione precedente era ancora in corso)');
            $table->smallInteger('exit_code')->nullable()->comment('Codice di uscita dell\'ultimo tentativo');
            $table->text('output')->nullable()->comment('Output dell\'ultimo tentativo (troncato)');
            $table->unsignedTinyInteger('attempts')->default(0)->comment('Tentativi eseguiti');
            $table->string('requested_by', 100)->nullable()->comment('Chi ha chiesto il lancio (nome del cliente del bridge)');
            $table->string('correlation_id', 64)->nullable()->comment('Identificativo che attraversa le app (intestazione X-Correlation-Id)');
            $table->timestamp('started_at')->nullable()->comment('Inizio dell\'ultimo tentativo');
            $table->timestamp('finished_at')->nullable()->comment('Fine dell\'esecuzione');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->index(['command', 'status'], 'command_runs_command_status_index');
            $table->index(['correlation_id'], 'command_runs_correlation_id_index');
            $table->comment('Lanci di comandi richiesti tramite il bridge API, con stato ed esito');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_runs');
    }
};
