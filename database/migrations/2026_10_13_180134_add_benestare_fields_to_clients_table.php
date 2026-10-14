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
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'richiesta_benestare')) {
                $table->text('richiesta_benestare')
                    ->nullable()
                    ->after('hiring_date')
                    ->comment('Istruzioni per ottenere la richiesta di benestare presso questo datore');
            }

            if (! Schema::hasColumn('clients', 'ritiro_benestare')) {
                $table->text('ritiro_benestare')
                    ->nullable()
                    ->after('richiesta_benestare')
                    ->comment("Istruzioni per il ritiro dell'atto di benestare");
            }

            if (! Schema::hasColumn('clients', 'notifica')) {
                $table->text('notifica')
                    ->nullable()
                    ->after('ritiro_benestare')
                    ->comment('Istruzioni per la notifica al datore');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['richiesta_benestare', 'ritiro_benestare', 'notifica']);
        });
    }
};
