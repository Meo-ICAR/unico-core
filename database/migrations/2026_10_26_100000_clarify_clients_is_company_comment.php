<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COMMENTO = 'Consulente aziendale (esterno con ruoli in organigramma); la persona giuridica si indica con is_person = false';

    /**
     * Connessione del database core, configurabile in config/unico-core.php.
     */
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    /**
     * `clients.is_company` non vuol dire «è un'azienda» ma «è un consulente aziendale»: si riscrive il commento, lasciando il resto
     * della definizione com'è. Non fa nulla su database diversi da MySQL o se la colonna manca.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $db = DB::connection($this->getConnection());

        if ($db->getDriverName() !== 'mysql' || ! $schema->hasColumn('clients', 'is_company')) {
            return;
        }

        $colonna = $db->selectOne('SHOW FULL COLUMNS FROM `clients` WHERE Field = ?', ['is_company']);

        if ($colonna->Comment === self::COMMENTO) {
            return;
        }

        $db->statement('ALTER TABLE `clients` MODIFY COLUMN `is_company` '.$colonna->Type.($colonna->Null === 'NO' ? ' NOT NULL' : ' NULL')
            .' DEFAULT '.$db->getPdo()->quote((string) ($colonna->Default ?? '0')).' COMMENT '.$db->getPdo()->quote(self::COMMENTO));
    }

    public function down(): void {}
};
