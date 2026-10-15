<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Models\Pratica;
use Unico\Core\Tests\Support\CoreDatabase;

class PraticaMediationFeeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::boot();
    }

    public function test_il_compenso_e_l_importo_fisso_altrimenti_la_percentuale_sull_importo_richiesto(): void
    {
        $this->assertSame(250.0, Pratica::mediationFee(250, 10, 10000));
        $this->assertSame(1000.0, Pratica::mediationFee(null, 10, 10000));
        $this->assertSame(1000.0, Pratica::mediationFee('', '10', '10000'));
        $this->assertNull(Pratica::mediationFee(null, 10, null));
        $this->assertNull(Pratica::mediationFee(null, null, 10000));
    }

    public function test_il_compenso_della_pratica_usa_i_suoi_campi(): void
    {
        $pratica = new Pratica(['amount' => 20000, 'mediation_fee_percent' => 5]);

        $this->assertSame(1000.0, $pratica->mediationFeeDue());
    }
}
