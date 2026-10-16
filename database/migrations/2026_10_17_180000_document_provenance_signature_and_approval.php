<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `documents`:
 * - `document_requirement_id`: da quale requisito di quale plico (task) nasce il documento;
 * - `signed_by_type` / `signed_by_id`: chi ha firmato (alias MorphMap, es. client, fornitore);
 * - `approved_by_type` / `approved_by_id` / `approved_at`: l'approvazione con firma prevista da `document_types.approver_model`
 *   (diversa dalla verifica del back office: `verified_by` / `verified_at`);
 * - `renewed_by` diventa il documento che è la nuova versione (prima conteneva l'utente che aveva rinnovato);
 * - `extracted_text` tolta: il testo letto sta in `document_analyses`.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('documents', 'document_requirement_id')) {
                $table->unsignedBigInteger('document_requirement_id')->nullable()->index()->after('document_type_id')->comment('Requisito del plico (task) da cui nasce il documento: fase, ruoli e chi firma');
            }

            if (! Schema::hasColumn('documents', 'signed_by_type')) {
                $table->string('signed_by_type', 30)->nullable()->after('signed_at')->comment('Alias MorphMap di chi ha firmato (es. client, fornitore)');
                $table->string('signed_by_id', 36)->nullable()->after('signed_by_type')->comment('ID di chi ha firmato');
            }

            if (! Schema::hasColumn('documents', 'approved_at')) {
                $table->string('approved_by_type', 30)->nullable()->after('verified_at')->comment('Alias MorphMap di chi ha approvato con la sua firma (document_types.approver_model)');
                $table->string('approved_by_id', 36)->nullable()->after('approved_by_type')->comment('ID di chi ha approvato con la sua firma');
                $table->timestamp('approved_at')->nullable()->after('approved_by_id')->comment('Data e ora dell\'approvazione con firma');
            }
        });

        // `renewed_by` puntava a users: ora punta al documento nuovo.
        $foreign = DB::selectOne("select CONSTRAINT_NAME as name from information_schema.KEY_COLUMN_USAGE where TABLE_SCHEMA = database() and TABLE_NAME = 'documents' and COLUMN_NAME = 'renewed_by' and REFERENCED_TABLE_NAME = 'users'");

        if ($foreign !== null) {
            Schema::table('documents', fn (Blueprint $table) => $table->dropForeign($foreign->name));
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('renewed_by')->nullable()->comment('Documento che è la nuova versione (rinnovo)')->change();
            $table->timestamp('delivered_at')->nullable()->comment('Data di invio del documento come allegato di un\'email')->change();
            $table->string('channel', 30)->nullable()->comment('Canale di arrivo: upload, email, whatsapp, drive, sharepoint, generated, signature, api')->change();
            $table->boolean('is_signed')->default(false)->comment('Il documento è stato firmato (nel tipo: deve essere firmato)')->change();
        });

        // Prima `renewed_by` conteneva l'utente che aveva rinnovato: ora è il documento nuovo, che `replaced_by_id` già indica.
        DB::table('documents')->whereNotNull('renewed_by')->update(['renewed_by' => DB::raw('replaced_by_id')]);

        if ($foreign !== null) {
            Schema::table('documents', fn (Blueprint $table) => $table->foreign('renewed_by')->references('id')->on('documents')->nullOnDelete());
        }

        if (Schema::hasColumn('documents', 'extracted_text')) {
            Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('extracted_text'));
        }
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            foreach (['approved_at', 'approved_by_id', 'approved_by_type', 'signed_by_id', 'signed_by_type', 'document_requirement_id'] as $column) {
                if (Schema::hasColumn('documents', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (! Schema::hasColumn('documents', 'extracted_text')) {
                $table->longText('extracted_text')->nullable();
            }
        });
    }
};
