<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dati dell'intervista preliminare (modulo prestiti personali) che mancavano:
 * stato civile, familiari, tipo occupazione, tipo assunzione, tipo reddito, mensilità, giorno stipendio, modalità di pagamento
 * e intestatario del conto sui clienti; grado di parentela sul legame tra pratica e coobbligato; finalità sulle pratiche.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasColumn('clients', 'marital_status')) {
            $schema->table('clients', function (Blueprint $table) {
                $table->string('marital_status', 30)->nullable()->after('sex')
                    ->comment('Stato civile: celibe_nubile, coniugato_comunione, coniugato_separazione, convivente, separato, divorziato, vedovo');
                $table->unsignedTinyInteger('family_members')->nullable()->after('marital_status')
                    ->comment('Numero di familiari');
                $table->unsignedTinyInteger('family_members_with_income')->nullable()->after('family_members')
                    ->comment('Numero di familiari con reddito');
                $table->unsignedBigInteger('occupation_type_id')->nullable()->after('profession')
                    ->comment('Tipo di occupazione (occupation_types.id)');
                $table->unsignedBigInteger('hiring_type_id')->nullable()->after('occupation_type_id')
                    ->comment('Tipo di assunzione (hiring_types.id)');
                $table->unsignedBigInteger('income_type_id')->nullable()->after('salary')
                    ->comment('Tipo di reddito, netto o lordo (income_types.id)');
                $table->unsignedTinyInteger('salary_months')->nullable()->after('income_type_id')
                    ->comment('Numero di mensilità del reddito');
                $table->unsignedTinyInteger('salary_day')->nullable()->after('salary_months')
                    ->comment('Giorno del mese in cui si riceve lo stipendio');
                $table->string('payment_method', 20)->nullable()->after('iban')
                    ->comment('Modalità di pagamento: sdd (addebito su conto corrente) o bollettino_postale');
                $table->string('iban_holder', 20)->nullable()->after('payment_method')
                    ->comment('Intestatario del conto corrente: richiedente o coobbligato');

                $table->foreign('occupation_type_id', 'clients_occupation_type_id_foreign')->references('id')->on('occupation_types')->nullOnDelete();
                $table->foreign('hiring_type_id', 'clients_hiring_type_id_foreign')->references('id')->on('hiring_types')->nullOnDelete();
                $table->foreign('income_type_id', 'clients_income_type_id_foreign')->references('id')->on('income_types')->nullOnDelete();
            });
        }

        if (! $schema->hasColumn('pratica_clients', 'kinship')) {
            $schema->table('pratica_clients', function (Blueprint $table) {
                $table->string('kinship', 30)->nullable()->after('role')
                    ->comment('Grado di parentela del coobbligato con il richiedente: coniuge, convivente, genitore, figlio, altra_parentela, altro');
            });
        }

        if (! $schema->hasColumn('pratiches', 'loan_purpose')) {
            $schema->table('pratiches', function (Blueprint $table) {
                $table->string('loan_purpose')->nullable()->comment('Finalità del finanziamento indicata nell\'intervista preliminare');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasColumn('clients', 'marital_status')) {
            $schema->table('clients', function (Blueprint $table) {
                $table->dropForeign('clients_occupation_type_id_foreign');
                $table->dropForeign('clients_hiring_type_id_foreign');
                $table->dropForeign('clients_income_type_id_foreign');
                $table->dropColumn(['marital_status', 'family_members', 'family_members_with_income', 'occupation_type_id', 'hiring_type_id', 'income_type_id', 'salary_months', 'salary_day', 'payment_method', 'iban_holder']);
            });
        }

        if ($schema->hasColumn('pratica_clients', 'kinship')) {
            $schema->table('pratica_clients', fn (Blueprint $table) => $table->dropColumn('kinship'));
        }

        if ($schema->hasColumn('pratiches', 'loan_purpose')) {
            $schema->table('pratiches', fn (Blueprint $table) => $table->dropColumn('loan_purpose'));
        }
    }
};
