<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Presa visione di un cambio di stato da parte dell'istruttore quando a farlo è stato un altro (es. il produttore). */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (! Schema::hasTable('pratica_status_history')) {
            return;
        }

        if (! Schema::hasColumn('pratica_status_history', 'seen_at')) {
            Schema::table('pratica_status_history', fn (Blueprint $table) => $table->timestamp('seen_at')->nullable()->after('user_id')->comment('Quando l\'istruttore ha preso visione del cambio di stato'));
        }

        if (! Schema::hasColumn('pratica_status_history', 'seen_by')) {
            Schema::table('pratica_status_history', fn (Blueprint $table) => $table->unsignedBigInteger('seen_by')->nullable()->after('seen_at')->comment('users.id di chi ha preso visione'));
        }
    }

    public function down(): void
    {
        foreach (['seen_by', 'seen_at'] as $column) {
            if (Schema::hasColumn('pratica_status_history', $column)) {
                Schema::table('pratica_status_history', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
