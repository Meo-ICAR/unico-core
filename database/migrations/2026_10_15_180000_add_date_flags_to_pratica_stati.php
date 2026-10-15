<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Gli stati che valorizzano in automatico la data della pratica: invio in banca, approvazione, erogazione (il rifiuto è `is_rejected`). */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const STATI = [
        'is_sended' => ['INVIO IN ISTRUTTORIA', 'Caricata Banca'],
        'is_approved' => ['DELIBERATA', 'Approvata'],
        'is_erogated' => ['LIQUIDATA'],
    ];

    public function up(): void
    {
        foreach (self::STATI as $colonna => $codici) {
            if (! Schema::hasColumn('pratica_stati', $colonna)) {
                Schema::table('pratica_stati', fn (Blueprint $table) => $table->boolean($colonna)->default(false)->after('is_rejected')->comment('Lo stato valorizza la data della pratica'));
            }

            DB::table('pratica_stati')->whereIn('codice', $codici)->update([$colonna => true]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::STATI) as $colonna) {
            if (Schema::hasColumn('pratica_stati', $colonna)) {
                Schema::table('pratica_stati', fn (Blueprint $table) => $table->dropColumn($colonna));
            }
        }
    }
};
