<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** `document_types.issuer_model` ammette anche `institute`: il documento lo emette la banca (contratto di finanziamento, delibera...). */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (Schema::hasColumn('document_types', 'issuer_model')) {
            Schema::table('document_types', fn (Blueprint $table) => $table->string('issuer_model', 30)->nullable()->comment('Chi emette di norma il documento: client (richiedente, se nullo), employer (datore di lavoro: cessione e delega) o institute (la banca)')->change());
        }

        DB::connection($this->getConnection())->table('document_types')
            ->whereIn('name', ['Contratto di finanziamento', 'Delibera banca', 'Contabile di liquidazione', 'Informazioni europee di base sul credito (SECCI)'])
            ->update(['issuer_model' => 'institute']);
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->table('document_types')->where('issuer_model', 'institute')->update(['issuer_model' => null]);
    }
};
