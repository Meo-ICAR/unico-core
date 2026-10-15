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

    /** Indirizzo del portale della banca dove l'agente inserisce la pratica. */
    public function up(): void
    {
        Schema::table('clientis', function (Blueprint $table) {
            if (! Schema::hasColumn('clientis', 'portal_url')) {
                $table->string('portal_url', 255)->nullable()->after('website')
                    ->comment('Portale della banca per l\'immissione delle pratiche da parte degli agenti');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clientis', function (Blueprint $table) {
            if (Schema::hasColumn('clientis', 'portal_url')) {
                $table->dropColumn('portal_url');
            }
        });
    }
};
