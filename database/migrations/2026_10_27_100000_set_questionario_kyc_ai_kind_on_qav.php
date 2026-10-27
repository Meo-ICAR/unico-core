<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    /**
     * Il QAV firmato si legge con il profilo «questionario_kyc»: anagrafica, domicilio e le otto risposte di adeguata verifica.
     * Solo dove il profilo è ancora vuoto.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasTable('document_types') || ! $schema->hasColumn('document_types', 'ai_kind')) {
            return;
        }

        DB::connection($this->getConnection())->table('document_types')
            ->where('name', 'QAV Persona fisica')->whereNull('ai_kind')
            ->update(['ai_kind' => 'questionario_kyc']);
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->table('document_types')
            ->where('name', 'QAV Persona fisica')->where('ai_kind', 'questionario_kyc')
            ->update(['ai_kind' => null]);
    }
};
