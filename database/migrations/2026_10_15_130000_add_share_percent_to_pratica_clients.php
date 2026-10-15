<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pratica_clients', 'share_percent')) {
            Schema::table('pratica_clients', function (Blueprint $table) {
                $table->decimal('share_percent', 5, 2)->nullable()->after('role')->comment('Quota percentuale del soggetto sul finanziamento (es. cointestatari)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pratica_clients', 'share_percent')) {
            Schema::table('pratica_clients', function (Blueprint $table) {
                $table->dropColumn('share_percent');
            });
        }
    }
};
