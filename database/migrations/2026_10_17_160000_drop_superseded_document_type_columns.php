<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tolte da `document_types` le colonne superate: chi riceve il documento è il taskable del task, ordine e fase stanno nel task,
 * come si ottiene è `source`, persona fisica o giuridica è `client_scope`. `source` vale anche fornitore ed employee
 * (documenti forniti dall'agente o dal dipendente, es. attestati dei corsi di formazione).
 */
return new class extends Migration
{
    private const COLUMNS = [
        'is_person', 'nature', 'is_template',
        'is_client', 'is_agent', 'is_employee', 'is_principal', 'is_practice', 'document_typable',
        'priority', 'phase', 'cellposition',
    ];

    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        $columns = array_values(array_filter(self::COLUMNS, fn (string $column): bool => Schema::hasColumn('document_types', $column)));

        if ($columns !== []) {
            Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn($columns));
        }

        Schema::table('document_types', fn (Blueprint $table) => $table->string('source', 20)->nullable()->comment('Come si ottiene: cliente, fornitore, employee (lo caricano loro), api (richiesto con api_service), template (lo inviamo da file o URL), stampa (compilato con pdf_modules e i nostri dati)')->change());
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            foreach (['is_person' => true, 'is_template' => false, 'is_client' => false, 'is_agent' => false, 'is_employee' => false, 'is_principal' => false, 'is_practice' => false] as $column => $default) {
                if (! Schema::hasColumn('document_types', $column)) {
                    $table->boolean($column)->default($default);
                }
            }

            foreach (['nature', 'document_typable', 'phase', 'cellposition'] as $column) {
                if (! Schema::hasColumn('document_types', $column)) {
                    $table->string($column)->nullable();
                }
            }

            if (! Schema::hasColumn('document_types', 'priority')) {
                $table->integer('priority')->default(0);
            }
        });
    }
};
