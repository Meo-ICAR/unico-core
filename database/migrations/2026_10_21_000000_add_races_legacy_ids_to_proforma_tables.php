<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Id dei dump Races per i proformas e le scritture di prima nota: nella destinazione hanno già altre righe con gli stessi id,
 * quindi quelle del dump si inseriscono con un id nuovo e questa colonna permette di ritrovarle se l'import si rilancia.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        $this->add('proformas', 'Id del proforma nel dump storico Races (proforma_races.proformas.id)');
        $this->add('prima_nota_entries', 'Id della scrittura nel dump storico Races (proforma_races.prima_nota_entries.id)');
    }

    public function down(): void
    {
        foreach (['proformas', 'prima_nota_entries'] as $table) {
            if (! Schema::hasColumn($table, 'legacy_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropUnique($table.'_legacy_id_unique');
                $blueprint->dropColumn('legacy_id');
            });
        }
    }

    private function add(string $table, string $comment): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'legacy_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($comment): void {
            $blueprint->string('legacy_id', 36)->nullable()->unique()->comment($comment);
        });
    }
};
