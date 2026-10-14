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
        Schema::table('pratiches', function (Blueprint $table) {
            if (! Schema::hasColumn('pratiches', 'mediation_fee_percent')) {
                $table->decimal('mediation_fee_percent', 5, 2)->nullable()->comment('Compenso del mediatore in percentuale sull\'importo richiesto (alternativo all\'importo fisso)');
            }

            if (! Schema::hasColumn('pratiches', 'mediation_fee_amount')) {
                $table->decimal('mediation_fee_amount', 12, 2)->nullable()->comment('Compenso del mediatore come importo fisso');
            }

            if (! Schema::hasColumn('pratiches', 'mediation_fee_terms')) {
                $table->text('mediation_fee_terms')->nullable()->comment('Modalità e tempi di pagamento del compenso di mediazione');
            }

            if (! Schema::hasColumn('pratiches', 'istruttoria_costs')) {
                $table->decimal('istruttoria_costs', 12, 2)->nullable()->comment('Stima delle spese di istruttoria o dei rimborsi spesa previsti');
            }

            if (! Schema::hasColumn('pratiches', 'offer_valid_until')) {
                $table->date('offer_valid_until')->nullable()->comment('Data fino alla quale l\'offerta di mediazione è valida');
            }

            if (! Schema::hasColumn('pratiches', 'withdrawal_terms')) {
                $table->text('withdrawal_terms')->nullable()->comment('Clausole relative al diritto di recesso');
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'profession')) {
                $table->string('profession', 150)->nullable()->after('employment_typology')->comment('Professione o attività svolta (adeguata verifica)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'profession')) {
                $table->dropColumn('profession');
            }
        });

        Schema::table('pratiches', function (Blueprint $table) {
            foreach (['mediation_fee_percent', 'mediation_fee_amount', 'mediation_fee_terms', 'istruttoria_costs', 'offer_valid_until', 'withdrawal_terms'] as $column) {
                if (Schema::hasColumn('pratiches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
