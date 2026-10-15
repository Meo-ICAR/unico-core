<?php

namespace Unico\Core\Tests;

use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Unico\Core\Clients\OpenApiCompanyService;

class OpenApiCompanyServiceTest extends TestCase
{
    private const TOKEN = 'token-di-test';

    private const VAT = '09006331210';

    private ?Container $previous = null;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository([
            'unico-core' => [
                'openapi' => [
                    'token' => self::TOKEN,
                    'company_url' => 'https://company.openapi.com',
                    'timeout' => 5,
                ],
            ],
        ]));
        Facade::setFacadeApplication($app);
        $app->instance(Factory::class, new Factory);
        Facade::clearResolvedInstance(Factory::class);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        if ($this->previous !== null) {
            Container::setInstance($this->previous);
            Facade::setFacadeApplication($this->previous);
        }
        parent::tearDown();
    }

    public function test_chiede_it_advanced_e_mappa_la_risposta(): void
    {
        $payload = json_decode((string) file_get_contents(__DIR__.'/Fixtures/openapi-it-advanced.json'), true);
        Http::fake([
            'https://company.openapi.com/IT-advanced/'.self::VAT => Http::response($payload),
        ]);

        $result = (new OpenApiCompanyService)->fetchCompany(self::VAT);

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://company.openapi.com/IT-advanced/'.self::VAT
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        Http::assertSentCount(1);

        $this->assertSame('HASSISTO S.R.L. *** START-UP COSTITUITA A NORMA DELL\'ART. 4 COMMA 10 BIS DEL DECRETO LEGGE 24 GENNAIO N. 3 ***', $result['company']['name']);
        $this->assertSame(self::VAT, $result['company']['vat']);
        $this->assertSame(self::VAT, $result['company']['tax_code']);
        $this->assertSame('hassisto@pec.it', $result['company']['pec']);
        $this->assertSame('62.01', $result['company']['ateco']);
        $this->assertSame("SOCIETA' A RESPONSABILITA' LIMITATA", $result['company']['legal_form']);
        $this->assertSame('2018-07-10', $result['company']['started_at']);
        $this->assertSame('KRRH6B9', $result['company']['sdi_code']);
        $this->assertSame('NA 1001542', $result['company']['cciaa']);
        $this->assertSame('ATTIVA', $result['company']['activity_status']);
        $this->assertSame('VIA MERCATO ITTICO 16, 80018 MUGNANO DI NAPOLI (NA)', $result['company']['address']);
        $this->assertSame([
            'address' => 'VIA MERCATO ITTICO 16',
            'street_number' => '16',
            'city' => 'MUGNANO DI NAPOLI',
            'zip_code' => '80018',
            'province' => 'NA',
            'region' => 'CAMPANIA',
            'founded_at' => '2018-07-10',
        ], $result['company']['office']);
        $this->assertSame([
            'year' => 2025,
            'employees' => 1,
            'turnover' => 324998,
            'net_worth' => -5074,
            'share_capital' => 10000,
        ], $result['company']['balance']);
        $this->assertSame([
            ['is_company' => false, 'name' => 'MEO', 'first_name' => 'PIER GIUSEPPE', 'tax_code' => 'MEOPGS64D04F839I', 'percent' => 35.0],
            ['is_company' => false, 'name' => 'PALMA', 'first_name' => 'GIUSEPPE', 'tax_code' => 'PLMGPP82S13D862A', 'percent' => 15.0],
            ['is_company' => false, 'name' => 'MAGLIULO', 'first_name' => 'MARIO', 'tax_code' => 'MGLMRA64E10F839W', 'percent' => 20.0],
            ['is_company' => false, 'name' => 'SOSCIA', 'first_name' => 'ERNESTO', 'tax_code' => 'SSCRST73C26F839R', 'percent' => 15.0],
            ['is_company' => false, 'name' => 'ORLANDO', 'first_name' => 'ALBINA', 'tax_code' => 'RLNLBN71H61F839W', 'percent' => 15.0],
        ], $result['shareholders']);
    }

    public function test_non_chiama_openapi_se_la_partita_iva_non_e_valida(): void
    {
        Http::fake();

        try {
            (new OpenApiCompanyService)->fetchCompany('12345678900');
            $this->fail('Attesa RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('La Partita IVA non è valida.', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_chiede_i_soci_solo_se_mancano_nella_risposta_advanced(): void
    {
        Http::fake([
            'https://company.openapi.com/IT-advanced/*' => Http::response([
                'success' => true,
                'data' => [[
                    'companyName' => 'ACME S.R.L.',
                    'vatCode' => '12345678903',
                    'taxCode' => '12345678903',
                ]],
            ]),
            'https://company.openapi.com/IT-shareholders/*' => Http::response([
                'success' => true,
                'data' => [[
                    'companyName' => 'HOLDING S.R.L.',
                    'taxCode' => '12345678903',
                    'percentShare' => 100,
                ]],
            ]),
        ]);

        $result = (new OpenApiCompanyService)->fetchCompany('12345678903');

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://company.openapi.com/IT-advanced/12345678903');
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://company.openapi.com/IT-shareholders/12345678903');
        $this->assertTrue($result['shareholders'][0]['is_company']);
        $this->assertSame('HOLDING S.R.L.', $result['shareholders'][0]['name']);
    }
}
