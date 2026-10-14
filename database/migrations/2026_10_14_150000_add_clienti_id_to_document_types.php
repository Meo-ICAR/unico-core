<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    /**
     * Documenti specifici di una banca (es. l'informativa Compass): da stampare e far firmare solo se sulla pratica
     * `clienti_id` è quell'istituto. Senza valore il documento vale per tutte le banche.
     */
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            if (! Schema::hasColumn('document_types', 'clienti_id')) {
                $table->unsignedBigInteger('clienti_id')->nullable()->after('company_id')->index('document_types_clienti_id_index')
                    ->comment('Istituto (clientis) a cui è legato il documento: se valorizzato serve solo sulle pratiche di quella banca');
            }
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            if (Schema::hasColumn('document_types', 'clienti_id')) {
                $table->dropIndex('document_types_clienti_id_index');
                $table->dropColumn('clienti_id');
            }
        });
    }
};
