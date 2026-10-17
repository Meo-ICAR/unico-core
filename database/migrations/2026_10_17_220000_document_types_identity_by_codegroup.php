<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Il documento d'identità si riconosce dal gruppo del tipo (`document_types.codegroup` = IDENTITA): il flag `is_identity`
 * è superfluo e viene tolto, i tipi che lo avevano passano al gruppo.
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
            return;
        }

        DB::connection($this->getConnection())->table('document_types')->where('is_identity', true)->update(['codegroup' => 'IDENTITA']);

        Schema::table('document_types', function (Blueprint $table): void {
            $table->dropColumn('is_identity');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('document_types', 'is_identity')) {
            return;
        }

        Schema::table('document_types', function (Blueprint $table): void {
            $table->boolean('is_identity')->default(false)->after('is_signed')->comment('Documento d\'identità: ha numero e ente di rilascio e, fra più documenti dello stesso cliente, vale il più recente');
        });

        DB::connection($this->getConnection())->table('document_types')->where('codegroup', 'IDENTITA')->update(['is_identity' => true]);
    }
};
