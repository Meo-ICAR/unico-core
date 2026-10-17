<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - `document_types.issuer_model`: chi fornisce il documento, `client` (il richiedente della pratica; se nullo vale lo stesso) o
 *   `employer` (il cliente di cui il richiedente è dipendente, coinvolto solo per cessione e delega);
 * - `pratiches.allows_direct_requests`: eccezione sulla pratica al flag del produttore (nullo = vale quello del produttore).
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (! Schema::hasColumn('document_types', 'issuer_model')) {
            Schema::table('document_types', fn (Blueprint $table) => $table->string('issuer_model', 30)->nullable()->after('approver_model')->comment('Chi fornisce il documento: client (richiedente della pratica, se nullo) o employer (cliente di cui il richiedente è dipendente; solo cessione e delega)'));
        }

        if (! Schema::hasColumn('pratiches', 'allows_direct_requests')) {
            Schema::table('pratiches', fn (Blueprint $table) => $table->boolean('allows_direct_requests')->nullable()->comment('Eccezione al flag del produttore: richieste dirette di documenti al cliente o all\'emittente (nullo = vale quello del produttore)'));
        }

        DB::connection($this->getConnection())->table('document_types')->where('name', 'Benestare del datore di lavoro o ente')->update(['issuer_model' => 'employer']);
    }

    public function down(): void
    {
        foreach ([['pratiches', 'allows_direct_requests'], ['document_types', 'issuer_model']] as [$table, $column]) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
            }
        }
    }
};
