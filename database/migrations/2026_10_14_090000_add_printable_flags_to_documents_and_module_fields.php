<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        Schema::table('pdf_module_fields', function (Blueprint $table) {
            if (! Schema::hasColumn('pdf_module_fields', 'is_required')) {
                $table->boolean('is_required')
                    ->default(false)
                    ->after('checkbox_when')
                    ->comment('Campo obbligatorio: il modulo è stampabile solo se la sua chiave dati (source_key) è valorizzata');
            }
        });

        Schema::table('documents', function (Blueprint $table) {
            if (! Schema::hasColumn('documents', 'is_printable')) {
                $table->boolean('is_printable')
                    ->default(false)
                    ->after('is_signed')
                    ->comment('Modulo da firmare pronto per la stampa: tutti i campi obbligatori del modulo sono valorizzati nel record associato');
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (Schema::hasColumn('documents', 'is_printable')) {
                $table->dropColumn('is_printable');
            }
        });

        Schema::table('pdf_module_fields', function (Blueprint $table) {
            if (Schema::hasColumn('pdf_module_fields', 'is_required')) {
                $table->dropColumn('is_required');
            }
        });
    }
};
