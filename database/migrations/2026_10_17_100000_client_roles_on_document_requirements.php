<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `document_requirements.client_roles`: i ruoli della pratica (richiedente, coobbligato, garante) a cui si applica il documento
 * richiesto sul client; nullo = tutti. Sostituisce `client_role` (un solo ruolo).
 * Se il client è persona fisica o giuridica lo dice `document_types.is_person` (vero = persona fisica, falso = persona giuridica).
 *
 * Dati esistenti: nei task di pratica i documenti identificativi (identità, codice fiscale), di privacy e le visure passano
 * al client; la visura è un documento della persona giuridica.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (! Schema::hasColumn('document_requirements', 'client_roles')) {
            Schema::table('document_requirements', fn (Blueprint $table) => $table->json('client_roles')->nullable()->after('documentable')->comment('Ruoli della pratica a cui si applica il documento sul client (richiedente, coobbligato, garante); nullo = tutti'));
        }

        if (Schema::hasColumn('document_requirements', 'client_role')) {
            DB::table('document_requirements')->whereNotNull('client_role')->orderBy('id')->each(
                fn ($row) => DB::table('document_requirements')->where('id', $row->id)->update(['client_roles' => json_encode([$row->client_role])]),
            );
            Schema::table('document_requirements', fn (Blueprint $table) => $table->dropColumn('client_role'));
        }

        DB::table('document_types')->where('ai_kind', 'visura')->update(['is_person' => false]);

        $daSpostare = DB::table('document_requirements as r')
            ->join('tasks as t', 't.id', '=', 'r.task_id')
            ->join('document_types as d', 'd.id', '=', 'r.document_type_id')
            ->where('t.taskable', 'pratica')
            ->whereNull('r.documentable')
            ->where(fn ($query) => $query
                ->whereIn('d.ai_kind', ['identita', 'codice_fiscale', 'informativa', 'visura'])
                ->orWhere('d.name', 'like', '%privacy%'))
            ->pluck('r.id');

        DB::table('document_requirements')->whereIn('id', $daSpostare)->update(['documentable' => 'client']);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('document_requirements', 'client_role')) {
            Schema::table('document_requirements', fn (Blueprint $table) => $table->string('client_role', 20)->nullable()->after('documentable'));
        }

        if (Schema::hasColumn('document_requirements', 'client_roles')) {
            Schema::table('document_requirements', fn (Blueprint $table) => $table->dropColumn('client_roles'));
        }
    }
};
