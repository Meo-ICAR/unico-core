<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - tutti i prestiti (nome che comincia per «Prestito») calcolano la provvigione sugli interessi;
 * - `pratiches.scadenza`: scadenza dell'impegno di terzi (fine del finanziamento, dell'assegno di mantenimento, della ritenuta CRAL);
 * - prodotto «Ritenuta CRAL», di terzi come l'assegno di mantenimento.
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

        $db->table('tipoprodotto')->where('name', 'like', 'Prestito%')->update(['tipo_provvigioni' => 'Interessi']);

        if (! Schema::hasColumn('pratiches', 'scadenza')) {
            Schema::table('pratiches', fn (Blueprint $table) => $table->date('scadenza')->nullable()->comment('Scadenza dell\'impegno di terzi: fine del finanziamento, dell\'assegno di mantenimento o della ritenuta CRAL'));
        }

        if (! $db->table('tipoprodotto')->where('name', 'Ritenuta CRAL')->exists()) {
            $db->table('tipoprodotto')->insert(['name' => 'Ritenuta CRAL', 'tipo_prodotto' => 'ritenuta_cral', 'is_third_party' => true, 'for_person' => true, 'for_company' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pratiches', 'scadenza')) {
            Schema::table('pratiches', fn (Blueprint $table) => $table->dropColumn('scadenza'));
        }

        DB::connection($this->getConnection())->table('tipoprodotto')->where('name', 'Ritenuta CRAL')->delete();
    }
};
