<?php

namespace Unico\Core\Tests;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Unico\Core\Models\Client;
use Unico\Core\Models\TipoProdotto;
use Unico\Core\Models\TipoProdottoSub;
use Unico\Core\Tests\Support\CoreDatabase;

class ClientEmploymentTypologyTest extends TestCase
{
    private const EMPLOYMENT_TYPOLOGIES = [
        'Statale',
        'Pubblico',
        'Privato SPA',
        'Privato Altra forma',
        'Privato Small Business',
        'Postale',
        'Ferroviere',
        'Parapubblico',
        'Medico',
        'Pensionato INPS',
        'Pensionato INPDAP',
        'Pensionato altri enti',
    ];

    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    public function test_core_schema_stores_employment_typology_and_hiring_date_on_clients(): void
    {
        $this->assertTrue(Schema::connection('core')->hasColumns('clients', [
            'employment_typology',
            'hiring_date',
        ]));

        $client = Client::query()->create([
            'name' => 'Rossi',
            'employment_typology' => 'Statale',
            'hiring_date' => '2020-01-15',
        ]);

        $this->assertSame('Statale', $client->employment_typology);
        $this->assertSame('2020-01-15', $client->hiring_date?->toDateString());
    }

    public function test_migration_populates_all_cession_employment_typologies_without_duplicates(): void
    {
        $cession = TipoProdotto::query()
            ->where('tipo_prodotto', 'Cessione')
            ->sole();

        $names = TipoProdottoSub::query()
            ->whereBelongsTo($cession, 'tipoprodotto')
            ->whereIn('name', self::EMPLOYMENT_TYPOLOGIES)
            ->pluck('name')
            ->all();

        $expected = self::EMPLOYMENT_TYPOLOGIES;
        sort($names);
        sort($expected);

        $this->assertSame($expected, $names);
    }
}
