<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'ereditarietà dei requisiti (mutuo per ristrutturazione dal mutuo) usa `inherits_task_id`: `parent_id` resta la sequenza dei plichi,
 * dove i figli si attivano al completamento del padre e gli applicabili sono solo i radice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tasks', 'inherits_task_id')) {
            Schema::table('tasks', fn (Blueprint $table) => $table->unsignedBigInteger('inherits_task_id')->nullable()->index()->after('parent_id')->comment('Task da cui eredita i requisiti documentali'));
        }

        // I task che ereditavano dal padre (prodotto diverso da quello del padre) passano al nuovo campo.
        DB::table('tasks as t')
            ->join('tasks as p', 'p.id', '=', 't.parent_id')
            ->whereNotNull('t.tipoprodotto_id')
            ->whereColumn('t.tipoprodotto_id', '<>', 'p.tipoprodotto_id')
            ->update(['t.inherits_task_id' => DB::raw('t.parent_id'), 't.parent_id' => null]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('tasks', 'inherits_task_id')) {
            Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('inherits_task_id'));
        }
    }
};
