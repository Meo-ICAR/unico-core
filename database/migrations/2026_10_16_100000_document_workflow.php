<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - `document_types.max_age_days`: oltre questa età dall'emissione il documento è un'anomalia (es. visura non più vecchia di 3 mesi).
 * - `tasks.tipoprodotto_id`: il fascicolo si lega al prodotto per riferimento e non per nome; il nome in `trigger_value` resta per i trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('document_types', 'max_age_days')) {
            Schema::table('document_types', fn (Blueprint $table) => $table->unsignedSmallInteger('max_age_days')->nullable()->comment('Età massima dall\'emissione, in giorni'));
        }

        if (! Schema::hasColumn('tasks', 'tipoprodotto_id')) {
            Schema::table('tasks', fn (Blueprint $table) => $table->unsignedBigInteger('tipoprodotto_id')->nullable()->index()->after('trigger_value')->comment('tipoprodotto.id del trigger tipo_prodotto'));
        }

        DB::table('tasks')->where('trigger_field', 'tipo_prodotto')->whereNull('tipoprodotto_id')->orderBy('id')->each(function ($task): void {
            $id = DB::table('tipoprodotto')->where('name', $task->trigger_value)->value('id');

            if ($id !== null) {
                DB::table('tasks')->where('id', $task->id)->update(['tipoprodotto_id' => $id]);
            }
        });

        DB::table('document_types')->where('name', 'Visura camerale')->whereNull('max_age_days')->update(['max_age_days' => 90]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('tasks', 'tipoprodotto_id')) {
            Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('tipoprodotto_id'));
        }

        if (Schema::hasColumn('document_types', 'max_age_days')) {
            Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn('max_age_days'));
        }
    }
};
