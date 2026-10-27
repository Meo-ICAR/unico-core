<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Models\Comune;
use Unico\Core\Seeders\ComuniSeeder;
use Unico\Core\Tests\Support\CoreDatabase;

class ComuniTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('comuni');
        (new ComuniSeeder)->run();
    }

    public function test_il_seeder_carica_tutti_i_comuni_ed_e_ripetibile(): void
    {
        $totale = Comune::query()->count();

        $this->assertGreaterThan(7800, $totale);
        $polistena = Comune::query()->where('cadastral_code', 'G791')->firstOrFail();
        $this->assertSame(['Polistena', 'RC'], [$polistena->name, $polistena->province]);
        $this->assertSame('Polistena (RC)', $polistena->label());

        (new ComuniSeeder)->run();
        $this->assertSame($totale, Comune::query()->count());
    }

    public function test_il_comune_si_riconosce_con_o_senza_provincia_senza_badare_a_maiuscole_e_apostrofi(): void
    {
        foreach (['POLISTENA (RC)', 'POLISTENA', 'polistena', ' Polistena (rc) '] as $testo) {
            $this->assertSame('G791', Comune::resolve($testo)?->cadastral_code, $testo);
        }

        $this->assertSame('Villa d\'Almè (BG)', Comune::resolve('VILLA D ALME')?->label());
        $this->assertSame('Aci Sant\'Antonio (CT)', Comune::resolve('aci sant\'antonio (CT)')?->label());
    }

    public function test_nel_dubbio_non_sceglie(): void
    {
        $this->assertNull(Comune::resolve(null));
        $this->assertNull(Comune::resolve('   '));
        $this->assertNull(Comune::resolve('Comune inesistente (RC)'));
        $this->assertNull(Comune::resolve('Polistena (MI)'), 'Provincia sbagliata: nessun comune.');

        // Stesso nome in due province: senza la provincia non si sceglie, con la provincia sì.
        $omonimo = Comune::query()->select('name')->groupBy('name')->havingRaw('count(*) > 1')->value('name');
        $this->assertNotNull($omonimo);
        $this->assertNull(Comune::resolve($omonimo));
        $provincia = Comune::query()->where('name', $omonimo)->value('province');
        $this->assertSame($provincia, Comune::resolve("{$omonimo} ({$provincia})")?->province);
    }
}
