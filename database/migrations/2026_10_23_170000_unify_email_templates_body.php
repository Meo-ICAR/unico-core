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
     * Il corpo del template è il solo campo `body` (HTML con segnaposto) in tutte le app. Le colonne legacy
     * `body_html` e `body_text` (usate in passato da unicogdpr) diventano facoltative e il loro contenuto si
     * riporta in `body` dove questo è vuoto. Non si elimina nulla.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasTable('email_templates') || ! $schema->hasColumn('email_templates', 'body_html')) {
            return;
        }

        $connection = DB::connection($this->getConnection());

        $connection->table('email_templates')
            ->where('body', '')
            ->whereNotNull('body_html')
            ->update(['body' => DB::raw('body_html')]);

        if ($connection->getDriverName() === 'mysql') {
            $connection->statement("ALTER TABLE email_templates MODIFY COLUMN body_html LONGTEXT NULL COMMENT 'Obsoleto: usare body'");

            if ($schema->hasColumn('email_templates', 'body_text')) {
                $connection->statement("ALTER TABLE email_templates MODIFY COLUMN body_text TEXT NULL COMMENT 'Obsoleto: usare body'");
            }
        }
    }

    public function down(): void {}
};
