<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'età massima di un documento si esprime con la durata del tipo (`duration`, `duration_unit`, `is_monitored`) e con
 * `documents.expires_at`, non con una colonna a parte: sostituisce `document_types.max_age_days`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_types')->where('name', 'Visura camerale')->update(['duration' => 3, 'duration_unit' => 'months', 'is_monitored' => true]);

        if (Schema::hasColumn('document_types', 'max_age_days')) {
            Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn('max_age_days'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('document_types', 'max_age_days')) {
            Schema::table('document_types', fn (Blueprint $table) => $table->unsignedSmallInteger('max_age_days')->nullable()->comment('Età massima dall\'emissione, in giorni'));
        }
    }
};
