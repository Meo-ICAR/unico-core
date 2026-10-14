<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    /**
     * L'informativa privacy firmata dal cliente è letta dall'AI e controllata (modulo giusto, firma presente).
     * Solo se il tipo non ha già un profilo di lettura: non si tocca ciò che è stato impostato a mano.
     */
    public function up(): void
    {
        DB::connection($this->getConnection())
            ->table('document_types')
            ->where('name', 'Informativa privacy')
            ->whereNull('ai_kind')
            ->update([
                'ai_kind' => 'informativa',
                'checks' => json_encode(['leggibile', 'tipo_documento', 'informativa_firmata']),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::connection($this->getConnection())
            ->table('document_types')
            ->where('name', 'Informativa privacy')
            ->where('ai_kind', 'informativa')
            ->update(['ai_kind' => null, 'checks' => null, 'updated_at' => now()]);
    }
};
