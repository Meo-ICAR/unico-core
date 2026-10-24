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
     * Specializzazione degli istruttori: i tipi prodotto e le banche (clientis) che seguono. Vuote = nessuna specializzazione,
     * l'istruttore può prendere qualsiasi pratica. Servono ad assegnare l'istruttore quando l'agente perfeziona la pratica.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasTable('employees') || $schema->hasColumn('employees', 'tipoprodotto_ids')) {
            return;
        }

        $schema->table('employees', function (Blueprint $table) {
            $table->json('tipoprodotto_ids')->nullable()->comment('Specializzazione istruttore: id dei tipi prodotto seguiti (tipoprodotto.id)');
            $table->json('clienti_ids')->nullable()->comment('Specializzazione istruttore: id delle banche seguite (clientis.id)');
        });
    }

    public function down(): void
    {
        // I campi restano: contengono le specializzazioni degli istruttori.
    }
};
