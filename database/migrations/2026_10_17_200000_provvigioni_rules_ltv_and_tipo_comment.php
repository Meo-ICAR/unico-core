<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `provvigioni_rules` come matrice delle provvigioni attive (quelle che la banca riconosce): per il mutuo la provvigione dipende dal
 * rapporto fra importo e valore dell'immobile (LTV), quindi la regola vale per una fascia `ltv_min`–`ltv_max`. Il tipo di calcolo
 * ammette anche `interessi` (prestiti: quota degli interessi) e `premio` (polizze).
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (! Schema::hasColumn('provvigioni_rules', 'ltv_min')) {
            Schema::table('provvigioni_rules', function (Blueprint $table): void {
                $table->decimal('ltv_min', 5, 2)->nullable()->after('tipo_provvigioni')->comment('LTV minimo (%) della fascia a cui si applica la regola (mutui). NULL = senza limite inferiore.');
                $table->decimal('ltv_max', 5, 2)->nullable()->after('ltv_min')->comment('LTV massimo (%) della fascia a cui si applica la regola (mutui), incluso. NULL = senza limite superiore.');
            });
        }

        Schema::table('provvigioni_rules', function (Blueprint $table): void {
            $table->string('tipo_provvigioni', 30)->default('lordo')->comment('Base di calcolo: lordo (montante: rata x numero rate), erogato, netto, interessi (quota degli interessi), premio (polizze), fixed (importo fisso)')->change();
        });
    }

    public function down(): void
    {
        Schema::table('provvigioni_rules', function (Blueprint $table): void {
            $table->dropColumn(['ltv_min', 'ltv_max']);
        });
    }
};
