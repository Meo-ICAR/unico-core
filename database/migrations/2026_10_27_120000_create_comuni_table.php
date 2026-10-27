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
     * Elenco dei comuni italiani (fonte ISTAT, come `database/data/codici-catastali.json`): serve a riconoscere il luogo di nascita
     * o di residenza scritto con o senza la provincia («Polistena» e «Polistena (RC)» sono lo stesso comune). I dati li carica
     * `ComuniSeeder`, nessuna migration li inserisce.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasTable('comuni')) {
            return;
        }

        $schema->create('comuni', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->comment('Nome del comune, come scritto dall\'ISTAT (senza provincia)');
            $table->char('province', 2)->comment('Sigla della provincia (RC, MI, ...)');
            $table->char('cadastral_code', 4)->unique()->comment('Codice catastale (Belfiore), lo stesso del codice fiscale');
            $table->index('name');
            $table->index(['name', 'province']);
        });
    }

    public function down(): void
    {
        // La tabella resta: è un'anagrafica di riferimento.
    }
};
