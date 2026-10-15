<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Unico\Core\Clients\OpenApiCadastreService;
use Unico\Core\Tests\Support\CoreDatabase;

class OpenApiCadastreServiceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::boot();
    }

    protected function setUp(): void
    {
        config(['unico-core.openapi.catasto_live' => false]);
    }

    public function test_spento_restituisce_una_risposta_simulata(): void
    {
        $r = (new OpenApiCadastreService)->lookup(' rssmra80a01h501u ', 'Via Roma 1, 00100 Roma');

        $this->assertFalse($r['live']);
        $this->assertSame('RSSMRA80A01H501U', $r['tax_code']);
        $this->assertNotEmpty($r['parcels']);
    }

    public function test_senza_codice_fiscale_o_indirizzo_non_si_attiva_nulla(): void
    {
        $this->expectException(RuntimeException::class);

        (new OpenApiCadastreService)->lookup('', 'Via Roma 1');
    }

    public function test_la_chiamata_reale_non_e_ancora_attiva(): void
    {
        config(['unico-core.openapi.catasto_live' => true]);
        $this->expectException(RuntimeException::class);

        (new OpenApiCadastreService)->lookup('RSSMRA80A01H501U', 'Via Roma 1');
    }
}
