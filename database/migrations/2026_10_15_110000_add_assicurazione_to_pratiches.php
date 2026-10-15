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
        if (! Schema::hasTable('pratiches') || Schema::hasColumn('pratiches', 'assicurazione')) {
            return;
        }

        Schema::table('pratiches', function (Blueprint $table) {
            $table->string('assicurazione')->nullable()->comment('Compagnia assicurativa abbinata alla pratica (cessione del quinto / delega)');
        });
    }

    public function down(): void
    {
        // Non si toglie: la colonna è condivisa e può contenere assicurazioni già indicate.
    }
};
