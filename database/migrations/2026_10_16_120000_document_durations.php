<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Scadenza dei documenti che le banche vogliono recenti o ancora validi, con lo schema durata + monitoraggio:
 * - certificati anagrafici (stato di famiglia, stato libero): 6 mesi;
 * - documento d'identità e patente: hanno già la durata, si monitorano perché una scadenza passata blocca la pratica.
 * La visura camerale (3 mesi) è nella migrazione precedente.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['Stato di famiglia', 'Stato libero o certificato di matrimonio con annotazioni'] as $name) {
            DB::table('document_types')->where('name', $name)->whereNull('duration')->update(['duration' => 6, 'duration_unit' => 'months', 'is_monitored' => true]);
        }

        DB::table('document_types')->whereIn('name', ['Carta di Identità o Patente & cert. Residenza', 'Patente di Guida'])->whereNotNull('duration')->update(['is_monitored' => true]);
    }

    public function down(): void
    {
        DB::table('document_types')->whereIn('name', ['Stato di famiglia', 'Stato libero o certificato di matrimonio con annotazioni'])->update(['duration' => null, 'is_monitored' => false]);
        DB::table('document_types')->whereIn('name', ['Carta di Identità o Patente & cert. Residenza', 'Patente di Guida'])->update(['is_monitored' => false]);
    }
};
