<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** La fase in cui serve un documento (es. istruttoria, invio_banca) è del plico, non del tipo documento. */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (! Schema::hasColumn('document_requirements', 'phase')) {
            Schema::table('document_requirements', fn (Blueprint $table) => $table->string('phase', 30)->nullable()->after('sort_order')->comment('Fase del plico in cui serve il documento (es. istruttoria, invio_banca); nullo = sempre'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('document_requirements', 'phase')) {
            Schema::table('document_requirements', fn (Blueprint $table) => $table->dropColumn('phase'));
        }
    }
};
