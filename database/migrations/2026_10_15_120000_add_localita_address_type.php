<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Tipologia di indirizzo "Località": dove si trova l'immobile di un mutuo. */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('address_types')->where('name', 'Località')->exists()) {
            DB::table('address_types')->insert(['name' => 'Località']);
        }
    }

    public function down(): void
    {
        DB::table('address_types')->where('name', 'Località')->delete();
    }
};
