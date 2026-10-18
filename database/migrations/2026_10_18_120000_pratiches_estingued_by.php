<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** `pratiches.estingued_by`: per un impegno di terzi, la pratica (nostra) che lo estingue; nullo se non è estinto da una nostra pratica. */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (! Schema::hasColumn('pratiches', 'estingued_by')) {
            Schema::table('pratiches', function (Blueprint $table) {
                $table->unsignedBigInteger('estingued_by')->nullable()->index()->comment('Pratica che estingue questo impegno di terzi');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pratiches', 'estingued_by')) {
            Schema::table('pratiches', fn (Blueprint $table) => $table->dropColumn('estingued_by'));
        }
    }
};
