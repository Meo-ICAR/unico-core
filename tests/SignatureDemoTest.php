<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Signature\SignatureDemo;
use Unico\Core\Tests\Support\CoreDatabase;

class SignatureDemoTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::boot();
    }

    public function test_la_simulazione_si_attiva_solo_con_il_provider_fake_e_il_flag_demo(): void
    {
        config(['unico-core.signature.driver' => 'fake', 'unico-core.signature.demo' => true]);
        $this->assertTrue(SignatureDemo::attiva());

        config(['unico-core.signature.demo' => false]);
        $this->assertFalse(SignatureDemo::attiva());

        // Con il provider reale la simulazione non parte mai, anche con il flag acceso.
        config(['unico-core.signature.driver' => 'yousign', 'unico-core.signature.demo' => true]);
        $this->assertFalse(SignatureDemo::attiva());
    }

    public function test_il_codice_otp_della_simulazione_e_configurabile(): void
    {
        config(['unico-core.signature.demo_otp' => null]);
        $this->assertSame('123456', SignatureDemo::codice());

        config(['unico-core.signature.demo_otp' => '654321']);
        $this->assertSame('654321', SignatureDemo::codice());
    }
}
