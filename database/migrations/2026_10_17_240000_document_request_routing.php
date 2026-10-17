<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - `document_types.instructions`: cosa deve caricare o compilare l'operatore per questo documento;
 * - `document_requirements.request_to_issuer`: il documento si chiede all'emittente (per cessione e delega il datore di lavoro del
 *   cliente) e non al produttore;
 * - `fornitoris.allows_direct_requests`: il produttore abilita la richiesta diretta al cliente o all'emittente (es. documenti
 *   integrativi); spento, preferisce fornire lui i documenti e le richieste passano da lui.
 */
return new class extends Migration
{
    /** Documenti che per cessione e delega emette il datore di lavoro. */
    private const DAL_DATORE = [
        'Busta paga', 'Busta paga 1', 'Busta paga 2', 'Certificazione Unica (CU)',
        'Certificato di stipendio e quota cedibile', 'Benestare del datore di lavoro o ente',
    ];

    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (! Schema::hasColumn('document_types', 'instructions')) {
            Schema::table('document_types', fn (Blueprint $table) => $table->text('instructions')->nullable()->after('description')->comment('Istruzioni per l\'operatore: cosa caricare o compilare per questo documento'));
        }

        if (! Schema::hasColumn('document_requirements', 'request_to_issuer')) {
            Schema::table('document_requirements', fn (Blueprint $table) => $table->boolean('request_to_issuer')->default(false)->after('is_required')->comment('Il documento si chiede direttamente all\'emittente (cessione e delega: il datore di lavoro del cliente) e non al produttore'));
        }

        if (! Schema::hasColumn('fornitoris', 'allows_direct_requests')) {
            Schema::table('fornitoris', fn (Blueprint $table) => $table->boolean('allows_direct_requests')->default(false)->comment('Il produttore abilita la richiesta diretta di documenti al cliente o all\'emittente (es. integrativi); spento, preferisce fornirli lui'));
        }

        $db = DB::connection($this->getConnection());

        $db->table('document_requirements')
            ->whereIn('document_type_id', $db->table('document_types')->whereIn('name', self::DAL_DATORE)->select('id'))
            ->whereIn('task_id', $db->table('tasks')->whereIn('trigger_value', ['Cessione', 'Delega'])->where('trigger_field', 'tipo_prodotto')->select('id'))
            ->update(['request_to_issuer' => true]);
    }

    public function down(): void
    {
        foreach ([['fornitoris', 'allows_direct_requests'], ['document_requirements', 'request_to_issuer'], ['document_types', 'instructions']] as [$table, $column]) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
            }
        }
    }
};
