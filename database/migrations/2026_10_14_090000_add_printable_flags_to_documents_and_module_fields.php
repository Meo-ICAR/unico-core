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

        Schema::table('document_types', function (Blueprint $table) {
            if (! Schema::hasColumn('document_types', 'signer_model')) {
                $table->string('signer_model', 30)
                    ->nullable()
                    ->after('is_signed')
                    ->comment('Alias MorphMap di chi firma il documento (es. client, fornitore); nullo = nessuno');
            }

            if (! Schema::hasColumn('document_types', 'approver_model')) {
                $table->string('approver_model', 30)
                    ->nullable()
                    ->after('signer_model')
                    ->comment('Alias MorphMap di chi mette la firma di approvazione (es. fornitore, employee); nullo = nessuna approvazione');
            }
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            foreach (['approver_model', 'signer_model'] as $column) {
                if (Schema::hasColumn('document_types', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

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
