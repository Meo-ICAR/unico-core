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
     * Come il cliente paga le rate, come riceve il finanziamento e come vuole le comunicazioni periodiche della banca: dati del
     * contratto (modulo di raccolta dati) che si portano sulla pratica.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasTable('pratiches')) {
            return;
        }

        foreach ([
            'repayment_method' => 'Modalità di pagamento delle rate: sdd, bollettino_postale o trattenuta (sullo stipendio/pensione)',
            'disbursement_method' => 'Modalità di erogazione del finanziamento: bonifico o assegno',
            'periodic_communication' => 'Comunicazioni periodiche di trasparenza della banca: elettronica (email) o cartacea',
        ] as $column => $comment) {
            if (! $schema->hasColumn('pratiches', $column)) {
                $schema->table('pratiches', fn (Blueprint $table) => $table->string($column, 20)->nullable()->comment($comment));
            }
        }
    }

    public function down(): void
    {
        // Le colonne restano: contengono dati dei contratti.
    }
};
