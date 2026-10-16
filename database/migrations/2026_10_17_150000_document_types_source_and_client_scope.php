<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Riordino di `document_types`:
 * - `source`: come si ottiene il documento (cliente, api, template, stampa), al posto di `nature` + `is_template`;
 * - `client_scope`: per i documenti di clienti e pratiche, persona fisica / giuridica / entrambi, al posto di `is_person`;
 * - `is_company` torna al significato originale: documento aziendale interno (organigramma, verbali CdA);
 * - chi firma: `signer_model` vale `client` se il documento va firmato e non è indicato; il task lo può cambiare
 *   (`document_requirements.signer_model`, es. l'agente nell'onboarding).
 * Le colonne superate restano (le usano ancora altre app) e sono marcate DEPRECATO nel commento.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            if (! Schema::hasColumn('document_types', 'source')) {
                $table->string('source', 20)->nullable()->after('api_service')->comment('Come si ottiene: cliente (lo carica il cliente), api (richiesto con api_service), template (lo inviamo da file o URL), stampa (compilato con pdf_modules e i nostri dati)');
            }

            if (! Schema::hasColumn('document_types', 'client_scope')) {
                $table->string('client_scope', 20)->nullable()->after('is_company')->comment('Documenti di clienti e pratiche: persona_fisica, persona_giuridica, entrambi; nullo = entrambi');
            }
        });

        if (! Schema::hasColumn('document_requirements', 'signer_model')) {
            Schema::table('document_requirements', fn (Blueprint $table) => $table->string('signer_model', 30)->nullable()->after('client_roles')->comment('Chi firma in questo task (alias MorphMap, es. fornitore nell\'onboarding agente); nullo = quello del tipo documento'));
        }

        $moduli = DB::table('pdf_modules')->where('is_active', true)->pluck('document_type_id')->filter()->unique()->all();

        DB::table('document_types')->whereNull('source')->orderBy('id')->each(function ($type) use ($moduli): void {
            $source = match (true) {
                filled($type->api_service) => 'api',
                in_array($type->id, $moduli, false) || $type->nature === 'template_fillable' => 'stampa',
                (bool) $type->is_template => 'template',
                default => 'cliente',
            };

            $scope = match (true) {
                (bool) $type->is_person && (bool) $type->is_company => 'entrambi',
                (bool) $type->is_person => 'persona_fisica',
                (bool) $type->is_company => null,
                default => 'persona_giuridica',
            };

            DB::table('document_types')->where('id', $type->id)->update(['source' => $source, 'client_scope' => $scope]);
        });

        DB::table('document_types')->where('is_signed', true)->whereNull('signer_model')->update(['signer_model' => 'client']);

        Schema::table('document_types', function (Blueprint $table): void {
            $table->boolean('is_company')->default(false)->comment('Documento aziendale interno (organigramma, verbali CdA): fuori da pratiche, agenti e mandanti')->change();
            $table->string('signer_model', 30)->nullable()->comment('Alias MorphMap di chi firma (default client se is_signed); il task può indicarne un altro')->change();
            $table->boolean('is_person')->default(true)->comment('DEPRECATO: usare client_scope. Documento inerente persona')->change();
            $table->string('nature')->nullable()->default('incoming')->comment('DEPRECATO: usare source. Tipo flusso: incoming, template_fillable, compliance')->change();
            $table->boolean('is_template')->default(false)->comment('DEPRECATO: derivato da source (template, stampa). Forniamo noi il template')->change();
            foreach (['is_client' => 'clienti', 'is_agent' => 'agenti', 'is_employee' => 'dipendenti', 'is_principal' => 'mandanti', 'is_practice' => 'pratiche'] as $column => $who) {
                $table->boolean($column)->default(false)->comment("DEPRECATO: chi riceve il documento è il taskable del task. Richiesto a {$who}")->change();
            }
            $table->string('document_typable')->nullable()->comment('DEPRECATO: chi riceve il documento è il taskable del task')->change();
            $table->integer('priority')->default(0)->comment('DEPRECATO: ordine gestito nel task (document_requirements.sort_order)')->change();
            $table->string('phase')->nullable()->comment('DEPRECATO: fase gestita nel task')->change();
            $table->string('cellposition')->nullable()->comment('DEPRECATO: non più usato')->change();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('document_requirements', 'signer_model')) {
            Schema::table('document_requirements', fn (Blueprint $table) => $table->dropColumn('signer_model'));
        }

        Schema::table('document_types', function (Blueprint $table): void {
            foreach (['source', 'client_scope'] as $column) {
                if (Schema::hasColumn('document_types', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
