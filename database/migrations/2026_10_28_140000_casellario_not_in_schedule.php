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
     * Il casellario giudiziale non va in scadenziario: `is_monitored` del tipo (ricopiato sui documenti, dove si può forzare) passa a falso.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $db = DB::connection($this->getConnection());

        if (! $schema->hasTable('document_types') || ! $schema->hasTable('documents')) {
            return;
        }

        $ids = $db->table('document_types')->where('slug', 'casellario-giudiziale')->pluck('id');

        $db->table('document_types')->whereIn('id', $ids)->update(['is_monitored' => false]);
        $db->table('documents')->whereIn('document_type_id', $ids)->update(['is_monitored' => false]);
    }

    public function down(): void {}
};
