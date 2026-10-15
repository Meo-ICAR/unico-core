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

    public function up(): void
    {
        if (! Schema::hasTable('pratiches') || Schema::hasColumn('pratiches', 'employee_id')) {
            return;
        }

        Schema::table('pratiches', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('clienti_id')->comment('Istruttore (dipendente) che segue la pratica')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Non si toglie: la colonna è condivisa e può contenere assegnazioni già fatte.
    }
};
