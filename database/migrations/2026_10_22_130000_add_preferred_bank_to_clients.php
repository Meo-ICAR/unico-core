<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banca preferita del cliente: collega clients a clientis (le banche e gli istituti).
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasColumn('clients', 'preferred_bank_id')) {
            return;
        }

        $schema->table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('preferred_bank_id')->nullable()->after('employer_id')
                ->comment('Banca preferita del cliente (clientis.id)');
            $table->foreign('preferred_bank_id', 'clients_preferred_bank_id_foreign')->references('id')->on('clientis')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasColumn('clients', 'preferred_bank_id')) {
            $schema->table('clients', function (Blueprint $table) {
                $table->dropForeign('clients_preferred_bank_id_foreign');
                $table->dropColumn('preferred_bank_id');
            });
        }
    }
};
