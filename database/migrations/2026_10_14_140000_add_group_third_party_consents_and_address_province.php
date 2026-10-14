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

    /**
     * Consensi della dichiarazione privacy che mancavano (promozione di società del Gruppo e di terzi)
     * e provincia sugli indirizzi, che per le persone fisiche sostituiscono la sede.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'consent_group_at')) {
                $table->timestamp('consent_group_at')->nullable()->after('consent_profiling_at')
                    ->comment('Consenso alla promozione di prodotti e servizi di società del Gruppo del Titolare');
            }

            if (! Schema::hasColumn('clients', 'consent_third_parties_at')) {
                $table->timestamp('consent_third_parties_at')->nullable()->after('consent_group_at')
                    ->comment('Consenso alla promozione di prodotti e servizi di società terze');
            }
        });

        Schema::table('addresses', function (Blueprint $table) {
            if (! Schema::hasColumn('addresses', 'province')) {
                $table->string('province', 100)->nullable()->after('city')->comment('Provincia (sigla)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            foreach (['consent_third_parties_at', 'consent_group_at'] as $column) {
                if (Schema::hasColumn('clients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('addresses', function (Blueprint $table) {
            if (Schema::hasColumn('addresses', 'province')) {
                $table->dropColumn('province');
            }
        });
    }
};
