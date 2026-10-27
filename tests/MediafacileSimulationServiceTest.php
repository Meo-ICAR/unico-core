<?php

namespace Unico\Core\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;
use Unico\Core\Clients\MediafacileSimulationService;
use Unico\Core\Clients\SimulationUnavailable;

class MediafacileSimulationServiceTest extends TestCase
{
    private const URL = 'https://races.mediafacile.test/ws/ws_simulazione.php';

    private ?Container $previous = null;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository(['unico-core' => ['mediafacile' => ['timeout' => 5]]]));
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

    public function test_legge_gli_importi_nei_due_formati_e_distingue_le_offerte_valide(): void
    {
        $xml = '<Offerte>'
            .'<Offerta><Importo_erogato>12.345,67</Importo_erogato><Errore>2</Errore></Offerta>'
            .'<Offerta><Importo_erogato>10000.50</Importo_erogato><Errore>2</Errore></Offerta>'
            .'<Offerta><Importo_erogato>0</Importo_erogato><Errore>1</Errore></Offerta>'
            .'</Offerte>';

        $this->assertSame([
            ['valid' => true, 'erogato' => 12345.67],
            ['valid' => true, 'erogato' => 10000.5],
            ['valid' => false, 'erogato' => 0.0],
        ], MediafacileSimulationService::parse($xml));
        $this->assertSame([], MediafacileSimulationService::parse('<Offerte/>'));
    }

    public function test_un_xml_non_valido_non_si_legge(): void
    {
        $this->expectException(SimulationUnavailable::class);

        MediafacileSimulationService::parse('questo non è xml');
    }

    public function test_invia_passkey_e_parametri_in_post_come_form_e_restituisce_il_corpo(): void
    {
        Http::fake([self::URL.'*' => Http::response('<Offerte/>')]);

        $body = (new MediafacileSimulationService)->request(self::URL, 'PK', ['Durata' => '60', 'Provvigione' => '2,50']);

        $this->assertSame('<Offerte/>', $body);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && $request->isForm()
            && $request->data() === ['passkey' => 'PK', 'Durata' => '60', 'Provvigione' => '2,50']);
    }

    public function test_legge_la_risposta_vera_di_filemaker_con_banca_scartando_gli_errori_e_riportando_in_euro_gli_importi_x100(): void
    {
        $offers = MediafacileSimulationService::offers((string) file_get_contents(__DIR__.'/Fixtures/mediafacile-simulazione.xml'));

        $this->assertCount(3, $offers);
        $this->assertSame(['valid' => true, 'erogato' => 25645.84, 'bank' => 'CAPITALFIN', 'commission' => 108000.0, 'message' => null], $offers[0]);
        // FIGENPA dà l'erogato × 100 (2.131.328,62) ma il capitale è 36.000: si riporta in euro.
        $this->assertSame(21313.29, $offers[1]['erogato']);
        $this->assertSame('FIGENPA SPA', $offers[1]['bank']);
        $this->assertFalse($offers[2]['valid']);

        $summary = MediafacileSimulationService::summary($offers);
        $this->assertSame(2, $summary['count']);
        $this->assertSame(['amount' => 25645.84, 'bank' => 'CAPITALFIN'], $summary['max']);
        $this->assertSame(['amount' => 21313.29, 'bank' => 'FIGENPA SPA'], $summary['min']);
    }

    public function test_una_riga_di_testo_del_servizio_diventa_il_messaggio_dell_errore(): void
    {
        try {
            MediafacileSimulationService::offers('Campo ID_listino non fornito o non valorizzato.');
            $this->fail('Attesa SimulationUnavailable');
        } catch (SimulationUnavailable $e) {
            $this->assertSame('Campo ID_listino non fornito o non valorizzato.', $e->getMessage());
        }
    }

    public function test_un_errore_http_o_di_rete_diventa_simulazione_non_disponibile(): void
    {
        Http::fake([self::URL.'*' => Http::response('', 500)]);
        try {
            (new MediafacileSimulationService)->request(self::URL, 'PK', []);
            $this->fail('Attesa SimulationUnavailable');
        } catch (SimulationUnavailable $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }

        Http::fake([self::URL.'*' => fn () => throw new ConnectionException('timeout')]);
        try {
            (new MediafacileSimulationService)->request(self::URL, 'PK', []);
            $this->fail('Attesa SimulationUnavailable');
        } catch (SimulationUnavailable $e) {
            $this->assertStringContainsString('timeout', $e->getMessage());
        }
    }

    public function test_il_riepilogo_scarta_gli_errori_e_pesa_con_la_provvigione(): void
    {
        $xml = '<Offerte>'
            .'<Offerta><Istituto><Nome>Banca A</Nome></Istituto><Importo_erogato>10.000,00</Importo_erogato><Importo_provvigione>100,00</Importo_provvigione><Errore>2</Errore></Offerta>'
            .'<Offerta><Istituto><Nome>Banca B</Nome></Istituto><Importo_erogato>20.000,00</Importo_erogato><Importo_provvigione>300,00</Importo_provvigione><Errore>2</Errore></Offerta>'
            .'<Offerta><Istituto><Nome>Banca C</Nome></Istituto><Importo_erogato>99.000,00</Importo_erogato><Importo_provvigione>900,00</Importo_provvigione><Errore>1</Errore></Offerta>'
            .'</Offerte>';

        $summary = MediafacileSimulationService::summary(MediafacileSimulationService::offers($xml));

        $this->assertSame(2, $summary['count']);
        $this->assertTrue($summary['weighted']);
        $this->assertSame(17500.0, $summary['average']);
        $this->assertSame(['amount' => 20000.0, 'bank' => 'Banca B'], $summary['max']);
        $this->assertSame(['amount' => 10000.0, 'bank' => 'Banca A'], $summary['min']);
    }

    public function test_senza_provvigioni_la_media_e_semplice_e_senza_offerte_valide_non_c_e_riepilogo(): void
    {
        $senza = '<Offerte><Offerta><Importo_erogato>10000</Importo_erogato><Errore>2</Errore></Offerta>'
            .'<Offerta><Importo_erogato>20000</Importo_erogato><Errore>2</Errore></Offerta></Offerte>';
        $summary = MediafacileSimulationService::summary(MediafacileSimulationService::offers($senza));

        $this->assertFalse($summary['weighted']);
        $this->assertSame(15000.0, $summary['average']);
        $this->assertNull($summary['max']['bank']);
        $this->assertNull(MediafacileSimulationService::summary(MediafacileSimulationService::offers('<Offerte><Offerta><Importo_erogato>0</Importo_erogato><Errore>1</Errore></Offerta></Offerte>')));
    }

    public function test_senza_capitale_nella_risposta_il_tetto_e_il_montante_dei_parametri(): void
    {
        $xml = '<Offerte>'
            .'<Offerta><Importo_erogato>20.000,00</Importo_erogato><Errore>2</Errore></Offerta>'
            .'<Offerta><Importo_erogato>2.131.328,62</Importo_erogato><Errore>2</Errore></Offerta>'
            .'<Offerta><Importo_erogato>9.999.999,00</Importo_erogato><Errore>2</Errore></Offerta>'
            .'</Offerte>';

        $offers = MediafacileSimulationService::offers($xml, ['Importo_rata' => '280,00', 'Durata' => '120']);

        $this->assertSame([20000.0, 21313.29, 9999999.0], array_column($offers, 'erogato'));
        $this->assertSame([true, true, false], array_column($offers, 'valid'));
        // Senza parametri nessun tetto, e col prestito (importo richiesto) neppure.
        $this->assertSame(2131328.62, MediafacileSimulationService::offers($xml)[1]['erogato']);
        $this->assertSame(2131328.62, MediafacileSimulationService::offers($xml, ['Importo_rata' => '280,00', 'Durata' => '120', 'Importo_richiesto' => '15000,00'])[1]['erogato']);
    }

    public function test_run_fa_una_chiamata_per_scenario_registra_anche_quello_senza_offerte_e_riassume(): void
    {
        $uno = '<Offerte><Offerta><Istituto><Nome>Banca A</Nome></Istituto><Importo_erogato>15000,00</Importo_erogato><Errore>2</Errore></Offerta></Offerte>';
        $due = '<Offerte><Offerta><Istituto><Nome>Banca B</Nome></Istituto><Importo_erogato>9000,00</Importo_erogato><Errore>2</Errore></Offerta></Offerte>';
        Http::fake([self::URL.'*' => Http::sequence()->push($uno)->push($due)]);
        $registrati = [];

        $esito = (new MediafacileSimulationService)->run(self::URL, 'PK', ['best' => ['Durata' => '60'], 'worst' => ['Durata' => '72']],
            function (string $nome, array $params, string $raw, array $offers) use (&$registrati): void {
                $registrati[] = [$nome, $params['Durata'], count($offers)];
            });

        $this->assertSame([['best', '60', 1], ['worst', '72', 1]], $registrati);
        $this->assertCount(2, $esito['offers']);
        $this->assertSame(['amount' => 15000.0, 'bank' => 'Banca A'], $esito['summary']['max']);
        $this->assertSame(['amount' => 9000.0, 'bank' => 'Banca B'], $esito['summary']['min']);

        Http::fake(['https://altro.test/*' => Http::response('<Offerte><Offerta><Importo_erogato>0</Importo_erogato><Errore>1</Errore></Offerta></Offerte>')]);
        $registrati = [];

        try {
            (new MediafacileSimulationService)->run('https://altro.test/ws', 'PK', ['unico' => []], function (string $nome) use (&$registrati): void {
                $registrati[] = $nome;
            });
            $this->fail('Attesa SimulationUnavailable');
        } catch (SimulationUnavailable $e) {
            $this->assertSame('Nessuna offerta valida.', $e->getMessage());
            $this->assertSame(['unico'], $registrati);
        }
    }
}
