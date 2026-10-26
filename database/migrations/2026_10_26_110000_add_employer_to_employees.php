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
     * Datore di lavoro del dipendente: la società o lo studio (clients, persona giuridica) per cui lavora, come `clients.employer_id`.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasTable('employees') || $schema->hasColumn('employees', 'employer_id')) {
            return;
        }

        $schema->table('employees', function (Blueprint $table) {
            $table->unsignedBigInteger('employer_id')->nullable()->comment('Datore di lavoro (clients.id, persona giuridica)');
            $table->index('employer_id');
        });
    }

    public function down(): void
    {
        // La colonna resta: contiene i datori di lavoro già censiti.
    }
};
