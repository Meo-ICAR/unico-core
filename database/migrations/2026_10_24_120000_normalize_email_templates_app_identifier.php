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
     * `email_templates.app_identifier` è l'identificativo con cui le app cercano i loro template (unicobpm, unicooam, ...): i valori
     * scritti dal vecchio form («UnicoOAM», «UnicoFin») si riportano a quelli, in minuscolo.
     */
    public function up(): void
    {
        if (! Schema::connection($this->getConnection())->hasColumn('email_templates', 'app_identifier')) {
            return;
        }

        $table = DB::connection($this->getConnection())->table('email_templates');

        $table->clone()->where('app_identifier', 'UnicoFin')->update(['app_identifier' => 'unicofinance']);
        $table->clone()->whereNotNull('app_identifier')->update(['app_identifier' => DB::raw('LOWER(app_identifier)')]);
    }

    public function down(): void {}
};
