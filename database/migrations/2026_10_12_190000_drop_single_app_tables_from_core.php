<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
     * Tabelle di una sola app: unicooam, più chat_messages, lead_sources e onorabilita.
     * addresses e address_types restano nel core.
     */
    public function up(): void
    {
        $leadSourceKey = Schema::getConnection()->selectOne(
            "select CONSTRAINT_NAME as name from information_schema.KEY_COLUMN_USAGE where TABLE_SCHEMA = database() and TABLE_NAME = 'clients' and COLUMN_NAME = 'leadsource_id' and REFERENCED_TABLE_NAME = 'lead_sources'"
        );

        if ($leadSourceKey !== null) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropForeign(['leadsource_id']);
            });
        }

        foreach ([
            'audit_checklist_evaluations',
            'audit_findings',
            'audits',
            'quality_reviews',
            'remediations',
            'suspicious_activity_reports',
            'training_records',
            'client_mandates',
            'client_relations',
            'blacklist_clienti_fornitori',
            'oam_pratiches',
            'oam_semestrales',
            'chat_messages',
            'lead_sources',
            'onorabilita',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Le tabelle si ricreano dalle migration dell'app proprietaria.
    }
};
