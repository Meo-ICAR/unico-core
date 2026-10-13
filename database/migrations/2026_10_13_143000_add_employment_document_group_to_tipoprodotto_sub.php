<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const GROUPS_BY_CODE = [
        'EMP_PENS_INPS' => 'pensionati',
        'EMP_PENS_INPDAP' => 'pensionati',
        'EMP_PENS_ALTRI' => 'pensionati',
        'EMP_PRIVATO_SPA' => 'privati',
        'EMP_PRIV_ALTRO' => 'privati',
        'EMP_PRIV_SMALL' => 'privati',
        'EMP_STATALE' => 'pubblici',
        'EMP_PUBBLICO' => 'pubblici',
        'EMP_POSTALE' => 'pubblici',
        'EMP_FERROVIERE' => 'pubblici',
        'EMP_MEDICO' => 'pubblici',
        'EMP_PARAPUBBLICO' => 'parapubblici',
    ];

    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (! Schema::hasColumn('tipoprodotto_sub', 'employment_document_group')) {
            Schema::table('tipoprodotto_sub', function (Blueprint $table) {
                $table->string('employment_document_group')
                    ->nullable()
                    ->after('is_active')
                    ->comment('Raggruppamento checklist documentale cessione: pensionati, privati, pubblici, parapubblici');
            });
        }

        $connection = DB::connection($this->getConnection());

        foreach (self::GROUPS_BY_CODE as $code => $group) {
            $connection->table('tipoprodotto_sub')
                ->where('code', $code)
                ->update(['employment_document_group' => $group]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tipoprodotto_sub', 'employment_document_group')) {
            return;
        }

        Schema::table('tipoprodotto_sub', function (Blueprint $table) {
            $table->dropColumn('employment_document_group');
        });
    }
};
