<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `tipoprodotto.tipo_provvigioni` ammette anche «Interessi» (prestiti) e dice su quale campo della pratica si calcola la percentuale
 * del compenso dell'istituto: cessione e delega sull'erogato, prestito sugli interessi.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        $db = DB::connection($this->getConnection());

        if (Schema::hasColumn('tipoprodotto', 'tipo_provvigioni')) {
            $db->statement("ALTER TABLE tipoprodotto MODIFY tipo_provvigioni ENUM('Lordo','Erogato','Netto','Interessi') NULL COMMENT 'Base di calcolo delle provvigioni: lordo, erogato, netto o interessi'");
        }

        $db->table('tipoprodotto')->whereIn('name', ['Cessione', 'Delega'])->whereNull('tipo_provvigioni')->update(['tipo_provvigioni' => 'Erogato']);
        $db->table('tipoprodotto')->where('name', 'Prestito')->whereNull('tipo_provvigioni')->update(['tipo_provvigioni' => 'Interessi']);
    }

    public function down(): void
    {
        $db = DB::connection($this->getConnection());

        $db->table('tipoprodotto')->where('tipo_provvigioni', 'Interessi')->update(['tipo_provvigioni' => null]);
        $db->statement("ALTER TABLE tipoprodotto MODIFY tipo_provvigioni ENUM('Lordo','Erogato','Netto') NULL COMMENT 'Base di calcolo delle provvigioni: lordo, erogato o netto'");
    }
};
