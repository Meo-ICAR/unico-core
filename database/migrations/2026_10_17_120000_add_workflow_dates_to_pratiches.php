<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Date di avanzamento della pratica: presa in carico e inizio lavorazione (l'invio in istruttoria è `sended_at`). */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (! Schema::hasTable('pratiches')) {
            return;
        }

        foreach (['presa_in_carico_at' => ['Data di presa in carico della pratica', 'data_inserimento_pratica'], 'lavorazione_at' => ['Data di inizio lavorazione della pratica', 'presa_in_carico_at']] as $column => [$comment, $after]) {
            if (! Schema::hasColumn('pratiches', $column)) {
                Schema::table('pratiches', fn (Blueprint $table) => $table->date($column)->nullable()->after($after)->comment($comment));
            }
        }
    }

    public function down(): void
    {
        foreach (['lavorazione_at', 'presa_in_carico_at'] as $column) {
            if (Schema::hasColumn('pratiches', $column)) {
                Schema::table('pratiches', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
