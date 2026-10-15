<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il mediatore raccoglie solo ciò che serve alla fattibilità e al portale della banca: i dati catastali, la superficie,
 * l'occupazione e i dettagli dei lavori stanno già nei documenti allegati (atto, perizia, visura, preventivi).
 */
return new class extends Migration
{
    private const COLONNE = ['superficie_mq', 'catasto_foglio', 'catasto_particella', 'catasto_subalterno', 'catasto_categoria', 'stato_occupazione', 'impresa_lavori', 'descrizione_lavori'];

    public function up(): void
    {
        foreach (self::COLONNE as $colonna) {
            if (Schema::hasColumn('pratica_immobili', $colonna)) {
                Schema::table('pratica_immobili', fn (Blueprint $table) => $table->dropColumn($colonna));
            }
        }
    }

    public function down(): void
    {
        Schema::table('pratica_immobili', function (Blueprint $table) {
            $table->unsignedInteger('superficie_mq')->nullable();
            $table->string('catasto_foglio', 20)->nullable();
            $table->string('catasto_particella', 20)->nullable();
            $table->string('catasto_subalterno', 20)->nullable();
            $table->string('catasto_categoria', 10)->nullable();
            $table->string('stato_occupazione', 20)->nullable();
            $table->string('impresa_lavori')->nullable();
            $table->text('descrizione_lavori')->nullable();
        });
    }
};
