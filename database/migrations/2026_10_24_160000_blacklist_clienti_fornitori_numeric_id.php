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
     * Nel database non si usano più gli UUID: la chiave del divieto di un istituto verso un produttore torna un intero
     * autoincrementante. Le righe già presenti (se ce ne sono) si rinumerano nell'ordine in cui stanno.
     */
    public function up(): void
    {
        $db = DB::connection($this->getConnection());

        if (! Schema::connection($this->getConnection())->hasTable('blacklist_clienti_fornitori')) {
            return;
        }

        $id = $db->selectOne("SHOW FULL COLUMNS FROM `blacklist_clienti_fornitori` WHERE Field = 'id'");

        if ($id === null || ! str_starts_with(strtolower((string) $id->Type), 'char')) {
            return;
        }

        $db->statement('SET @n := 0');
        $db->statement('UPDATE `blacklist_clienti_fornitori` SET `id` = (@n := @n + 1) ORDER BY `created_at`, `cliente_id`, `fornitore_id`');
        $db->statement("ALTER TABLE `blacklist_clienti_fornitori` MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Identificativo interno del divieto di un istituto verso un produttore'");
    }

    public function down(): void {}
};
