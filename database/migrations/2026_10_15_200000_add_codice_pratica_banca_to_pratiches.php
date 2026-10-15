<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pratiches', 'codice_pratica_banca')) {
            Schema::table('pratiches', function (Blueprint $table) {
                $table->string('codice_pratica_banca', 100)->nullable()->index()->after('codice_pratica')->comment('Codice assegnato alla pratica dalla banca sul suo portale');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pratiches', 'codice_pratica_banca')) {
            Schema::table('pratiches', fn (Blueprint $table) => $table->dropColumn('codice_pratica_banca'));
        }
    }
};
