<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dati per l'intervista preliminare: tipo indirizzo «Storico» (indirizzo precedente) e i tipi di documento d'identità
 * che mancavano (passaporto, tessera ministeriale). Si aggiunge solo ciò che non c'è già.
 */
return new class extends Migration
{
    private const DOCUMENTS = [
        ['name' => 'Passaporto', 'code' => 'PASSAPORTO', 'slug' => 'passaporto'],
        ['name' => 'Tessera Ministeriale', 'code' => 'TESSERA_MINISTERIALE', 'slug' => 'tessera-ministeriale'],
    ];

    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        $db = DB::connection($this->getConnection());

        if (! $db->table('address_types')->where('name', 'Storico')->exists()) {
            $db->table('address_types')->insert(['name' => 'Storico']);
        }

        foreach (self::DOCUMENTS as $document) {
            if ($db->table('document_types')->where('name', $document['name'])->orWhere('code', $document['code'])->exists()) {
                continue;
            }

            $db->table('document_types')->insert($document + [
                'codegroup' => 'IDENTITA', 'is_person' => true, 'client_scope' => 'persona_fisica', 'source' => 'cliente',
                'is_monitored' => true, 'nature' => 'incoming', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->getConnection());

        $unused = $db->table('document_types')->whereIn('code', array_column(self::DOCUMENTS, 'code'))
            ->whereNotIn('id', $db->table('documents')->select('document_type_id')->whereNotNull('document_type_id'))->pluck('id');
        $db->table('document_types')->whereIn('id', $unused)->delete();
        $db->table('address_types')->where('name', 'Storico')
            ->whereNotIn('id', $db->table('addresses')->select('address_type_id')->whereNotNull('address_type_id'))->delete();
    }
};
