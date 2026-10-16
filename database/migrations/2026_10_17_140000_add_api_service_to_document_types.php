<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `document_types.api_service`: servizio API che può produrre il documento invece del caricamento (vedi `DocumentApiService`):
 * openapi_company (dati societari), openapi_creditscore, openapi_catasto. Nullo = solo caricamento.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (! Schema::hasColumn('document_types', 'api_service')) {
            Schema::table('document_types', fn (Blueprint $table) => $table->string('api_service', 40)->nullable()->after('ai_kind')->comment('Servizio API che può produrre il documento (openapi_company, openapi_creditscore, openapi_catasto); nullo = solo caricamento'));
        }

        DB::table('document_types')->where('ai_kind', 'visura')->whereNull('api_service')->update(['api_service' => 'openapi_company']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('document_types', 'api_service')) {
            Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn('api_service'));
        }
    }
};
