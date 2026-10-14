<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tipologie lavorative del gruppo autonomi, nel catalogo che la pratica usa per risalire al gruppo documentale.
     *
     * @var array<string, string>
     */
    private const AUTONOMI = [
        'Lavoratore autonomo' => 'EMP_AUTONOMO',
        'Libero professionista' => 'EMP_LIBERO_PROF',
    ];

    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (! Schema::hasColumn('tipoprodotto', 'company_id')) {
            Schema::table('tipoprodotto', function (Blueprint $table) {
                $table->foreignId('company_id')
                    ->nullable()
                    ->after('is_third_party')
                    ->comment('Company a cui è riservato il prodotto; nullo = prodotto valido per tutte le company')
                    ->constrained('companies')
                    ->nullOnDelete();
            });
        }

        Schema::table('tipoprodotto_sub', function (Blueprint $table) {
            $table->string('employment_document_group')
                ->nullable()
                ->comment('Raggruppamento checklist documentale per tipologia lavorativa: pensionati, privati, pubblici, parapubblici, autonomi')
                ->change();
        });

        $connection = DB::connection($this->getConnection());
        $productId = $connection->table('tipoprodotto')->where('tipo_prodotto', 'Cessione')->value('id');

        if ($productId === null) {
            return;
        }

        foreach (self::AUTONOMI as $name => $code) {
            $connection->table('tipoprodotto_sub')->updateOrInsert(
                ['tipoprodotto_id' => $productId, 'name' => $name],
                ['code' => $code, 'is_active' => true, 'employment_document_group' => 'autonomi', 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->table('tipoprodotto_sub')
            ->whereIn('code', array_values(self::AUTONOMI))
            ->delete();

        if (Schema::hasColumn('tipoprodotto', 'company_id')) {
            Schema::table('tipoprodotto', function (Blueprint $table) {
                $table->dropConstrainedForeignId('company_id');
            });
        }
    }
};
