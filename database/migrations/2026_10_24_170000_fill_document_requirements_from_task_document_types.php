<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Connessione del database core, configurabile in config/unico-core.php.
     */
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    /**
     * Il plico di un'attività (OnBoarding, OAM-Agenti, IVASS-Agenti, OffBoarding, OAM-Onorabilità, Audit...) era definito in
     * `task_document_types` (attività, tipo documento, obbligatorio); il codice legge solo `document_requirements`, rimasta
     * vuota. Si copiano le righe mancanti, nell'ordine in cui stavano: documento di destinazione e firmatario restano quelli del
     * plico (nessuna destinazione particolare). Ripetibile: una coppia attività + tipo documento già presente non si tocca.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasTable('task_document_types') || ! $schema->hasTable('document_requirements')) {
            return;
        }

        DB::connection($this->getConnection())->statement(<<<'SQL'
            INSERT INTO document_requirements (task_id, document_type_id, is_required, sort_order, created_at, updated_at)
            SELECT x.task_id, x.document_type_id, x.is_required, x.ordine, NOW(), NOW()
            FROM (
                SELECT t.task_id, t.document_type_id, t.is_required, ROW_NUMBER() OVER (PARTITION BY t.task_id ORDER BY t.id) * 10 AS ordine
                FROM task_document_types t
                JOIN tasks k ON k.id = t.task_id
                JOIN document_types d ON d.id = t.document_type_id
            ) x
            WHERE NOT EXISTS (
                SELECT 1 FROM document_requirements r WHERE r.task_id = x.task_id AND r.document_type_id = x.document_type_id
            )
        SQL);
    }

    public function down(): void {}
};
