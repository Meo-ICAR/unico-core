<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `document_requirements.documentable`: alias del model su cui nasce il documento richiesto da un task
 * (es. `client` per i documenti del cliente richiesti da un task di pratica); nullo = il model del task.
 * `document_requirements.client_role`: per `client`, il ruolo nella pratica (richiedente, coobbligato, garante); nullo = tutti.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (! Schema::hasColumn('document_requirements', 'documentable')) {
            Schema::table('document_requirements', fn (Blueprint $table) => $table->string('documentable', 50)->nullable()->after('task_id')->comment('Alias del model su cui nasce il documento; nullo = il model del task'));
        }

        if (! Schema::hasColumn('document_requirements', 'client_role')) {
            Schema::table('document_requirements', fn (Blueprint $table) => $table->string('client_role', 20)->nullable()->after('documentable')->comment('Per documentable=client: ruolo nella pratica (richiedente, coobbligato, garante); nullo = tutti'));
        }
    }

    public function down(): void
    {
        foreach (['client_role', 'documentable'] as $column) {
            if (Schema::hasColumn('document_requirements', $column)) {
                Schema::table('document_requirements', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
