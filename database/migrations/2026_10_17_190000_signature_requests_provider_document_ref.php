<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plico di firma: più documenti viaggiano in un'unica busta presso il provider (un solo OTP per il firmatario). Ogni documento
 * mantiene la sua richiesta (`signature_requests.document_id`); le richieste dello stesso plico condividono `provider_ref` e si
 * distinguono per `provider_document_ref`, il documento nella busta.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (Schema::hasColumn('signature_requests', 'provider_document_ref')) {
            return;
        }

        Schema::table('signature_requests', function (Blueprint $table): void {
            $table->string('provider_document_ref')->nullable()->after('provider_ref')->comment('ID del documento nella busta del provider (richieste dello stesso plico condividono provider_ref)');
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table): void {
            $table->dropColumn('provider_document_ref');
        });
    }
};
