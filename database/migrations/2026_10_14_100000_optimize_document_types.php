<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Colonne mai lette da nessuna app: le regole di applicabilità stanno su tasks, `regex_pattern` duplica `regex`. */
    private const UNUSED = ['regex_pattern', 'trigger_field', 'trigger_state', 'trigger_value', 'exclude_field', 'exclude_state', 'exclude_value'];

    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            foreach (self::UNUSED as $column) {
                if (Schema::hasColumn('document_types', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (! Schema::hasIndex('document_types', ['company_id'])) {
                $table->index(['company_id'], 'document_types_company_id_index');
            }

            if (! Schema::hasIndex('document_types', ['codegroup'])) {
                $table->index(['codegroup'], 'document_types_codegroup_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            if (Schema::hasIndex('document_types', ['codegroup'])) {
                $table->dropIndex('document_types_codegroup_index');
            }

            if (Schema::hasIndex('document_types', 'document_types_company_id_index')) {
                $table->dropIndex('document_types_company_id_index');
            }

            $columns = [
                'regex_pattern' => fn () => $table->string('regex_pattern')->nullable()->comment('Pattern di validazione'),
                'trigger_field' => fn () => $table->string('trigger_field')->nullable()->comment('Campo del modello da controllare'),
                'trigger_state' => fn () => $table->string('trigger_state')->nullable()->comment('filled, empty, equals'),
                'trigger_value' => fn () => $table->string('trigger_value')->nullable()->comment('Il valore specifico da controllare'),
                'exclude_field' => fn () => $table->string('exclude_field')->nullable()->comment('Campo del modello da escludere se valorizzato'),
                'exclude_state' => fn () => $table->string('exclude_state')->nullable()->comment('filled, empty, equals'),
                'exclude_value' => fn () => $table->string('exclude_value')->nullable()->comment('Il valore specifico da controllare'),
            ];

            foreach ($columns as $name => $add) {
                if (! Schema::hasColumn('document_types', $name)) {
                    $add();
                }
            }
        });
    }
};
