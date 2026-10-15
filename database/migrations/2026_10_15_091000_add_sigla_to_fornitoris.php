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
        if (! Schema::hasTable('fornitoris') || Schema::hasColumn('fornitoris', 'sigla')) {
            return;
        }

        Schema::table('fornitoris', function (Blueprint $table) {
            $table->string('sigla', 10)->nullable()->index()->comment('Sigla del produttore nei codici pratica (es. PM, SB); se manca si ricava dalle iniziali del nome');
        });
    }

    public function down(): void
    {
        // Non si toglie: la colonna è condivisa e può contenere sigle già usate nei codici pratica.
    }
};
