<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stato pratica distinto per tipo di finanziamento (stesso codice, workflow diversi). */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (! Schema::hasColumn('pratica_stati', 'tipo_finanziamento')) {
            Schema::table('pratica_stati', function (Blueprint $table) {
                $table->string('tipo_finanziamento')->nullable()->after('codice')
                    ->comment('Tipo di finanziamento (pratiches.tipo_prodotto) a cui si applica lo stato');
            });
        }

        $names = $this->indexNames();
        if (in_array('pratica_stati_codice_unique', $names, true)) {
            Schema::table('pratica_stati', function (Blueprint $table) {
                $table->dropUnique('pratica_stati_codice_unique');
            });
        }

        $names = $this->indexNames();
        if (! in_array('pratica_stati_codice_tipo_unique', $names, true)) {
            Schema::table('pratica_stati', function (Blueprint $table) {
                $table->unique(['codice', 'tipo_finanziamento'], 'pratica_stati_codice_tipo_unique');
            });
        }
    }

    public function down(): void
    {
        $names = $this->indexNames();
        if (in_array('pratica_stati_codice_tipo_unique', $names, true)) {
            Schema::table('pratica_stati', function (Blueprint $table) {
                $table->dropUnique('pratica_stati_codice_tipo_unique');
            });
        }

        $names = $this->indexNames();
        if (! in_array('pratica_stati_codice_unique', $names, true)) {
            Schema::table('pratica_stati', function (Blueprint $table) {
                $table->unique(['codice'], 'pratica_stati_codice_unique');
            });
        }

        if (Schema::hasColumn('pratica_stati', 'tipo_finanziamento')) {
            Schema::table('pratica_stati', function (Blueprint $table) {
                $table->dropColumn('tipo_finanziamento');
            });
        }
    }

    /** @return list<string> */
    private function indexNames(): array
    {
        $indexes = Schema::getIndexes('pratica_stati');

        return array_map(fn (array $index) => $index['name'] ?? '', $indexes);
    }
};
