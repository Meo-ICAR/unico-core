<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UUID dei dump Races dove la destinazione ha un id intero e la chiave naturale
 * non basta a ritrovare la riga (banche e fornitori senza P.IVA, documenti).
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        $this->add('clientis', 'UUID della banca nel dump storico Races (proforma_races.clientis.id)');
        $this->add('fornitoris', 'UUID del fornitore nel dump storico Races (proforma_races.fornitoris.id)');
        $this->add('documents', 'UUID del documento nel dump storico Races (unicooam_races.documents.id)');
    }

    public function down(): void
    {
        foreach (['clientis', 'fornitoris', 'documents'] as $table) {
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
            $blueprint->char('legacy_id', 36)->nullable()->unique()->comment($comment);
        });
    }
};
