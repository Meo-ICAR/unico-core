<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stati dell'invio: «INVIO IN ISTRUTTORIA» è interno (il plico va all'istruttoria) e non valorizza la data di invio; «INVIO IN BANCA»
 * è la pratica caricata sul portale della banca e valorizza `sended_at`. Gli stati del flusso si creano per ogni tipo di finanziamento,
 * così compaiono già nell'elenco del cambio di stato.
 */
return new class extends Migration
{
    private const STATI = [
        'PERFEZIONARE' => ['name' => 'PERFEZIONARE', 'ordine' => 21],
        'FASCICOLO COMPLETO' => ['name' => 'FASCICOLO COMPLETO', 'ordine' => 22],
        'INVIO IN ISTRUTTORIA' => ['name' => 'INVIO IN ISTRUTTORIA', 'ordine' => 23],
        'INVIO IN BANCA' => ['name' => 'Invio in banca', 'ordine' => 25],
    ];

    public function up(): void
    {
        $tipi = DB::table('pratica_stati')->whereNotNull('tipo_finanziamento')->distinct()->pluck('tipo_finanziamento');

        foreach ($tipi as $tipo) {
            foreach (self::STATI as $codice => $dati) {
                $esiste = DB::table('pratica_stati')->where('tipo_finanziamento', $tipo)->where('codice', $codice)->exists();

                if (! $esiste) {
                    DB::table('pratica_stati')->insert([
                        'codice' => $codice, 'tipo_finanziamento' => $tipo, 'name' => $dati['name'], 'ordine' => $dati['ordine'],
                        'is_rejected' => false, 'is_working' => true, 'is_estingued' => false, 'colore' => 'gray',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        DB::table('pratica_stati')->where('codice', 'INVIO IN ISTRUTTORIA')->update(['is_sended' => false]);
        DB::table('pratica_stati')->whereIn('codice', ['INVIO IN BANCA', 'Caricata Banca'])->update(['is_sended' => true]);
    }

    public function down(): void
    {
        DB::table('pratica_stati')->where('codice', 'INVIO IN BANCA')->delete();
    }
};
