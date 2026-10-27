<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tipo documento => profilo di lettura AI: identità (carta, patente, passaporto) e tessera sanitaria / codice fiscale. */
    private const KINDS = [
        "Carta d'Identità" => 'identita',
        'Carta di Identità o Patente & cert. Residenza' => 'identita',
        'Patente di Guida' => 'identita',
        'Passaporto' => 'identita',
        'Documento identificativo' => 'identita',
        'Secondo documento di riconoscimento (patente o passaporto)' => 'identita',
        'Tessera Sanitaria / Codice fiscale' => 'codice_fiscale',
        'Codice Fiscale' => 'codice_fiscale',
    ];

    public function getConnection()
    {
        return config('unico-core.connection');
    }

    /**
     * Senza il profilo il documento non si legge e non porta nulla nell'anagrafica del cliente. Solo dove è ancora vuoto:
     * un profilo scelto a mano non si tocca.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasTable('document_types') || ! $schema->hasColumn('document_types', 'ai_kind')) {
            return;
        }

        foreach (self::KINDS as $name => $kind) {
            DB::connection($this->getConnection())->table('document_types')->where('name', $name)->whereNull('ai_kind')->update(['ai_kind' => $kind]);
        }
    }

    public function down(): void
    {
        foreach (self::KINDS as $name => $kind) {
            DB::connection($this->getConnection())->table('document_types')->where('name', $name)->where('ai_kind', $kind)->update(['ai_kind' => null]);
        }
    }
};
