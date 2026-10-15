<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Stati di sospensione: le pratiche sospese si nascondono di default negli elenchi. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pratica_stati', 'is_suspended')) {
            Schema::table('pratica_stati', fn (Blueprint $table) => $table->boolean('is_suspended')->default(false)->after('is_rejected')->comment('Stato di sospensione della pratica'));
        }

        DB::table('pratica_stati')->whereIn('codice', ['SOSPESA', 'Sospesa Istruttoria Interna'])->update(['is_suspended' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('pratica_stati', 'is_suspended')) {
            Schema::table('pratica_stati', fn (Blueprint $table) => $table->dropColumn('is_suspended'));
        }
    }
};
