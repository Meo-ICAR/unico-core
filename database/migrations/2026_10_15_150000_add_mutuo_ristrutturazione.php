<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Mutuo per ristrutturazione: nuovo tipo prodotto e dati dei lavori sull'immobile. */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('tipoprodotto')->where('name', 'Mutuo ristrutturazione')->exists()) {
            $mutuo = (array) DB::table('tipoprodotto')->where('name', 'Mutuo')->first();
            unset($mutuo['id']);
            DB::table('tipoprodotto')->insert(array_merge($mutuo ?: [], ['name' => 'Mutuo ristrutturazione', 'tipo_prodotto' => 'Mutuo ristrutturazione']));
        }

        if (! Schema::hasColumn('pratica_immobili', 'importo_lavori')) {
            Schema::table('pratica_immobili', function (Blueprint $table) {
                $table->decimal('importo_lavori', 12, 2)->nullable()->after('prezzo_acquisto')->comment('Importo dei lavori di ristrutturazione');
                $table->string('impresa_lavori')->nullable()->after('importo_lavori')->comment('Impresa esecutrice dei lavori');
                $table->text('descrizione_lavori')->nullable()->after('impresa_lavori')->comment('Descrizione dei lavori');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pratica_immobili', 'importo_lavori')) {
            Schema::table('pratica_immobili', function (Blueprint $table) {
                $table->dropColumn(['importo_lavori', 'impresa_lavori', 'descrizione_lavori']);
            });
        }

        DB::table('tipoprodotto')->where('name', 'Mutuo ristrutturazione')->delete();
    }
};
