<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dove la company archivia i file dei documenti: locale, Google Drive o SharePoint. */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (Schema::hasColumn('companies', 'documents_storage')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->string('documents_storage', 30)->default('local')->after('drive_folder_id')
                ->comment('Archivio file documenti: local, google_drive, sharepoint');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('companies', 'documents_storage')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('documents_storage');
        });
    }
};
