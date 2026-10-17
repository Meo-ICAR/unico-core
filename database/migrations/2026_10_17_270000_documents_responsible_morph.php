<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** `documents.responsible_type` / `responsible_id`: chi deve far proseguire l'iter del documento nella fase in cui si trova (alias MorphMap). */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (Schema::hasColumn('documents', 'responsible_type')) {
            return;
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->string('responsible_type', 30)->nullable()->after('status')->comment('Alias MorphMap di chi deve far proseguire l\'iter del documento (client, fornitore, employee, cliente = istituto)');
            $table->string('responsible_id', 36)->nullable()->after('responsible_type')->comment('ID di chi deve far proseguire l\'iter del documento');
            $table->index(['responsible_type', 'responsible_id'], 'documents_responsible_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('documents', 'responsible_type')) {
            return;
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex('documents_responsible_index');
            $table->dropColumn(['responsible_type', 'responsible_id']);
        });
    }
};
