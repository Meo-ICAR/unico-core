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
     * Nome e cognome separati sono facoltativi: il dato canonico del dipendente è `name`. Dove le colonne
     * esistono già (database condiviso) restano con i loro valori e diventano nullable; dove mancano si aggiungono.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasTable('employees')) {
            return;
        }

        foreach (['first_name' => 'Nome', 'last_name' => 'Cognome'] as $column => $comment) {
            $exists = $schema->hasColumn('employees', $column);

            $schema->table('employees', function (Blueprint $table) use ($column, $comment, $exists): void {
                $definition = $table->string($column)->nullable()->comment($comment);

                if ($exists) {
                    $definition->change();
                }
            });
        }
    }

    public function down(): void {}
};
