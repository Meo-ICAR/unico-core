<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `document_types.is_identity`: il tipo è un documento d'identità (carta d'identità, patente, passaporto...). Solo per questi tipi il
 * documento porta numero e ente di rilascio; se un cliente ne ha più di uno vale il più recente (KYC, moduli).
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (! Schema::hasColumn('document_types', 'is_identity')) {
            Schema::table('document_types', function (Blueprint $table): void {
                $table->boolean('is_identity')->default(false)->after('is_signed')->comment('Documento d\'identità: ha numero e ente di rilascio e, fra più documenti dello stesso cliente, vale il più recente');
            });
        }

        DB::connection($this->getConnection())->table('document_types')
            ->where(fn ($query) => $query->whereIn('code', ['CARTA_IDENTITA', 'DOCUMENTO_IDENTIFICATIVO'])
                ->orWhereIn('name', ['Carta di Identità o Patente & cert. Residenza', "Carta d'Identità", 'Carta di Identità', 'Patente di Guida']))
            ->update(['is_identity' => true]);
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            $table->dropColumn('is_identity');
        });
    }
};
