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
    private const EMPLOYMENT_TYPOLOGIES = [
        'Statale' => 'EMP_STATALE',
        'Pubblico' => 'EMP_PUBBLICO',
        'Privato SPA' => 'EMP_PRIVATO_SPA',
        'Privato Altra forma' => 'EMP_PRIV_ALTRO',
        'Privato Small Business' => 'EMP_PRIV_SMALL',
        'Postale' => 'EMP_POSTALE',
        'Ferroviere' => 'EMP_FERROVIERE',
        'Parapubblico' => 'EMP_PARAPUBBLICO',
        'Medico' => 'EMP_MEDICO',
        'Pensionato INPS' => 'EMP_PENS_INPS',
        'Pensionato INPDAP' => 'EMP_PENS_INPDAP',
        'Pensionato altri enti' => 'EMP_PENS_ALTRI',
    ];

    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('employment_typology', 50)
                ->nullable()
                ->after('employer_id')
                ->comment('Tipologia lavorativa o pensionistica del cliente');
            $table->date('hiring_date')
                ->nullable()
                ->after('employment_typology')
                ->comment('Data di assunzione del cliente persona fisica');
        });

        $connection = DB::connection($this->getConnection());
        $cessionId = $connection->table('tipoprodotto')
            ->where('tipo_prodotto', 'Cessione')
            ->value('id');

        if ($cessionId === null) {
            $cessionId = $connection->table('tipoprodotto')->insertGetId([
                'name' => 'Cessione del Quinto',
                'tipo_prodotto' => 'Cessione',
                'code' => 'CQ',
                'is_external' => false,
                'is_active' => true,
                'is_oneclient' => true,
                'for_person' => true,
                'for_company' => false,
                'requires_mandate' => true,
                'is_third_party' => false,
                'tipo_provvigioni' => 'Erogato',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (self::EMPLOYMENT_TYPOLOGIES as $name => $code) {
            $connection->table('tipoprodotto_sub')->updateOrInsert(
                [
                    'tipoprodotto_id' => $cessionId,
                    'name' => $name,
                ],
                [
                    'code' => $code,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());
        $connection->table('tipoprodotto_sub')
            ->whereIn('code', array_values(self::EMPLOYMENT_TYPOLOGIES))
            ->delete();

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['employment_typology', 'hiring_date']);
        });
    }
};
