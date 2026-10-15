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
        if (Schema::hasColumn('tasks', 'company_id')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('parent_id')
                ->comment('Company a cui è riservato il task; nullo = valido per tutte le company')
                ->constrained('companies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('tasks', 'company_id')) {
            Schema::table('tasks', fn (Blueprint $table) => $table->dropConstrainedForeignId('company_id'));
        }
    }
};
