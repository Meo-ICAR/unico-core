<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `documents`: nel rinnovo `renewed_by` è l'utente che ha rinnovato e `renewed_by_id` il documento nuovo (il clone).
 * Annulla la scelta di `document_provenance_signature_and_approval`, dove `renewed_by` era diventato il documento nuovo.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (! Schema::hasTable('documents')) {
            return;
        }

        if (! Schema::hasColumn('documents', 'renewed_by_id')) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->unsignedBigInteger('renewed_by_id')->nullable()->after('renewed_by')->index()->comment('Documento nuovo che lo rinnova (clone)');
            });
        }

        $foreign = DB::selectOne("select CONSTRAINT_NAME as name from information_schema.KEY_COLUMN_USAGE where TABLE_SCHEMA = database() and TABLE_NAME = 'documents' and COLUMN_NAME = 'renewed_by' and REFERENCED_TABLE_NAME = 'documents'");

        if ($foreign !== null) {
            Schema::table('documents', fn (Blueprint $table) => $table->dropForeign($foreign->name));

            // Qui `renewed_by` conteneva il documento nuovo: passa in `renewed_by_id`; chi ha rinnovato non è noto.
            DB::table('documents')->whereNotNull('renewed_by')->update([
                'renewed_by_id' => DB::raw('renewed_by'),
                'renewed_by' => null,
            ]);

            Schema::table('documents', function (Blueprint $table): void {
                $table->unsignedBigInteger('renewed_by')->nullable()->comment('FK Utente che ha rinnovato il documento')->change();
                $table->foreign('renewed_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        $hasIdForeign = DB::selectOne("select CONSTRAINT_NAME as name from information_schema.KEY_COLUMN_USAGE where TABLE_SCHEMA = database() and TABLE_NAME = 'documents' and COLUMN_NAME = 'renewed_by_id' and REFERENCED_TABLE_NAME = 'documents'");

        if ($hasIdForeign === null) {
            Schema::table('documents', fn (Blueprint $table) => $table->foreign('renewed_by_id')->references('id')->on('documents')->nullOnDelete());
        }
    }

    public function down(): void
    {
        // Nessun ritorno: lo schema precedente perdeva l'utente che aveva rinnovato.
    }
};
