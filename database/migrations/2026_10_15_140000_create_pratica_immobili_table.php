<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pratica_immobili')) {
            return;
        }

        Schema::create('pratica_immobili', function (Blueprint $table) {
            $table->id()->comment('ID univoco dell\'immobile');
            $table->string('pratica_id', 36)->index()->comment('pratiches.id');
            $table->string('tipologia', 30)->nullable()->comment('abitazione | box | terreno | commerciale');
            $table->string('destinazione_uso', 30)->nullable()->comment('prima_casa | seconda_casa | investimento');
            $table->string('finalita', 30)->nullable()->comment('acquisto | surroga | liquidita | ristrutturazione');
            $table->decimal('valore_stimato', 12, 2)->nullable()->comment('Valore stimato o di perizia');
            $table->decimal('prezzo_acquisto', 12, 2)->nullable()->comment('Prezzo di acquisto');
            $table->unsignedInteger('superficie_mq')->nullable()->comment('Superficie in metri quadri');
            $table->string('catasto_foglio', 20)->nullable();
            $table->string('catasto_particella', 20)->nullable();
            $table->string('catasto_subalterno', 20)->nullable();
            $table->string('catasto_categoria', 10)->nullable()->comment('Categoria catastale (A/2, C/6, ...)');
            $table->string('stato_occupazione', 20)->nullable()->comment('libero | locato | occupato');
            $table->text('notes')->nullable()->comment('Note');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->comment('Immobili oggetto o garanzia di una pratica di mutuo; l\'indirizzo è un address di tipo Località');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pratica_immobili');
    }
};
