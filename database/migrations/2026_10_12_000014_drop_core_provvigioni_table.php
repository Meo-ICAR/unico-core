<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/** Le provvigioni restano sull'app Proforma, non sul database condiviso. */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        Schema::dropIfExists('provvigioni');
    }

    public function down(): void
    {
        // Non si ricrea: la tabella non appartiene al core.
    }
};
