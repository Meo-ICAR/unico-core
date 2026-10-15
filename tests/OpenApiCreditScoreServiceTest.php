<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Unico\Core\Clients\OpenApiCreditScoreService;
use Unico\Core\Tests\Support\CoreDatabase;

class OpenApiCreditScoreServiceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::boot();
    }

    protected function setUp(): void
    {
        config(['unico-core.openapi.creditscore_url' => 'https://test.risk.openapi.com/', 'unico-core.openapi.creditscore_live' => false]);
    }

    public function test_la_richiesta_e_simulata_e_riporta_l_indirizzo_che_si_interrogherebbe(): void
    {
        $result = (new OpenApiCreditScoreService)->request(' meopgs64d04f839i ');

        $this->assertTrue($result['simulated']);
        $this->assertSame('GET', $result['method']);
        $this->assertSame('https://test.risk.openapi.com/IT-creditscore-top/MEOPGS64D04F839I', $result['url']);
        $this->assertNull($result['score']);
    }

    public function test_senza_codice_fiscale_non_si_attiva_nulla(): void
    {
        $this->expectException(RuntimeException::class);

        (new OpenApiCreditScoreService)->request('  ');
    }

    public function test_la_chiamata_reale_non_e_ancora_attiva(): void
    {
        config(['unico-core.openapi.creditscore_live' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non è ancora attiva');

        (new OpenApiCreditScoreService)->request('MEOPGS64D04F839I');
    }
}
