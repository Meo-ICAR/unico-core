<?php

namespace Unico\Core\Clients;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Servizio di simulazione Mediafacile (WS Offerte): POST in form con `passkey` e i parametri, risposta XML di FileMaker
 * (`fmresultset`, un `record` per offerta). Di ogni offerta si legge l'importo erogato; l'offerta vale se `Errore` è 2.
 * Se un parametro obbligatorio manca il servizio risponde con una riga di testo (es. «Campo ID_listino non fornito…»).
 * L'URL e la Passkey sono della società (ognuna ha le sue): qui non si salva nulla.
 */
class MediafacileSimulationService
{
    /**
     * @param  array<string, string>  $params  senza Passkey
     *
     * @throws SimulationUnavailable
     */
    public function request(string $url, string $passkey, array $params): string
    {
        try {
            $response = Http::asForm()->timeout((int) config('unico-core.mediafacile.timeout', 45))
                ->post($url, ['passkey' => $passkey] + $params);
        } catch (ConnectionException $e) {
            throw new SimulationUnavailable('Servizio di simulazione non raggiungibile: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new SimulationUnavailable("Il servizio di simulazione ha risposto {$response->status()}.");
        }

        return $response->body();
    }

    /**
     * @return list<array{valid: bool, erogato: float}> una voce per ogni elemento che contiene Importo_erogato
     *
     * @throws SimulationUnavailable
     */
    public static function parse(string $raw): array
    {
        return array_map(
            fn (array $offer): array => ['valid' => $offer['valid'], 'erogato' => $offer['erogato']],
            self::offers($raw),
        );
    }

    /**
     * Come parse(), con la banca e la provvigione di ogni offerta (null se il servizio non le dà).
     * Con i `$params` inviati si ricava il montante (rata × durata), che fa da tetto all'erogato quando la risposta non dà il capitale.
     *
     * @param  array<string, string>  $params
     * @return list<array{valid: bool, erogato: float, bank: ?string, commission: ?float, message: ?string}>
     *
     * @throws SimulationUnavailable
     */
    public static function offers(string $raw, array $params = []): array
    {
        $raw = trim($raw);

        // Un parametro mancante o non valido: il servizio risponde con una riga di testo, non con XML.
        if ($raw === '' || ! str_starts_with($raw, '<')) {
            throw new SimulationUnavailable($raw === '' ? 'Risposta vuota del servizio di simulazione.' : mb_substr($raw, 0, 200));
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string(preg_replace('/<!DOCTYPE[^>]*>/', '', $raw) ?? $raw);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new SimulationUnavailable('Risposta del servizio di simulazione non valida.');
        }

        $ceiling = self::ceiling($params);

        if (isset($xml->xpath('/*[local-name()="fmresultset"]')[0])) {
            return self::fromFileMaker($xml, $ceiling);
        }

        $offers = [];
        foreach ($xml->xpath('//Importo_erogato/..') ?: [] as $node) {
            $offers[] = self::offer(
                trim((string) $node->Errore) === '2',
                (string) $node->Importo_erogato,
                null,
                self::bank($node),
                trim((string) $node->Importo_provvigione),
                trim((string) $node->Stato),
                $ceiling,
            );
        }

        return $offers;
    }

    /** @return list<array{valid: bool, erogato: float, bank: ?string, commission: ?float, message: ?string}> */
    private static function fromFileMaker(\SimpleXMLElement $xml, ?float $ceiling): array
    {
        $xml->registerXPathNamespace('f', 'http://www.filemaker.com/xml/fmresultset');
        $code = (string) ($xml->xpath('/f:fmresultset/f:error/@code')[0] ?? '0');

        if ($code !== '0' && $code !== '401') {
            throw new SimulationUnavailable("Il servizio di simulazione ha risposto con l'errore {$code}.");
        }

        $offers = [];
        foreach ($xml->xpath('//f:resultset/f:record') ?: [] as $record) {
            $record->registerXPathNamespace('f', 'http://www.filemaker.com/xml/fmresultset');
            $field = function (string $name) use ($record): string {
                $found = $record->xpath('f:field[@name="'.$name.'"]/f:data');

                return $found ? trim((string) $found[0]) : '';
            };

            $offers[] = self::offer(
                $field('Errore') === '2',
                $field('Importo_erogato'),
                $field('Importo_capitale'),
                $field('Denominazione_istituto') ?: null,
                $field('Importo_provvigione'),
                $field('Stato'),
                $ceiling,
            );
        }

        return $offers;
    }

    /**
     * Alcune banche (es. FIGENPA) hanno dato in passato l'erogato moltiplicato per 100. Il tetto è il montante rata × durata dei parametri
     * inviati o, se non ci sono, il capitale della risposta, preso com'è: se l'erogato lo supera e diviso per 100 rientra, si riporta in
     * euro; se lo supera ancora, l'offerta non è attendibile e non vale. Con importi già corretti non cambia nulla.
     *
     * @return array{valid: bool, erogato: float, bank: ?string, commission: ?float, message: ?string}
     */
    private static function offer(bool $valid, string $erogato, ?string $capital, ?string $bank, string $commission, string $message, ?float $ceiling = null): array
    {
        $amount = self::number($erogato);
        $limit = $ceiling ?? ($capital !== null && trim($capital) !== '' ? self::number($capital) : null);

        if ($limit !== null && $limit > 0 && $amount > $limit) {
            if ($amount / 100 <= $limit) {
                $amount = round($amount / 100, 2);
            } elseif ($valid) {
                $valid = false;
                $message = 'Erogato oltre il capitale.';
            }
        }

        return [
            'valid' => $valid,
            'erogato' => $amount,
            'bank' => $bank,
            'commission' => $commission === '' ? null : self::number($commission),
            'message' => $message === '' ? null : $message,
        ];
    }

    /** Montante (rata × durata) dai parametri inviati; null per il prestito (ha l'importo richiesto) o se mancano rata e durata. */
    private static function ceiling(array $params): ?float
    {
        if (isset($params['Importo_richiesto'])) {
            return null;
        }

        $rata = self::number((string) ($params['Importo_rata'] ?? ''));
        $durata = self::number((string) ($params['Durata'] ?? ''));

        return $rata > 0 && $durata > 0 ? $rata * $durata : null;
    }

    /**
     * Una o più simulazioni (scenari), ognuna con i suoi parametri: unicofinance ne fa una con i dati esatti, unicoagent due
     * (migliore e peggiore). Di ogni risposta si leggono le offerte (con il tetto del montante) e `$record` riceve nome, parametri,
     * risposta grezza e offerte, anche quando lo scenario non ha offerte valide. Le offerte valide di tutti gli scenari vanno nel riepilogo.
     *
     * @param  array<string, array<string, string>>  $scenarios  nome dello scenario => parametri, senza Passkey
     * @param  (\Closure(string, array<string, string>, string, list<array<string, mixed>>): void)|null  $record
     * @return array{offers: list<array{valid: bool, erogato: float, bank: ?string, commission: ?float, message: ?string}>, summary: array<string, mixed>}
     *
     * @throws SimulationUnavailable
     */
    public function run(string $url, string $passkey, array $scenarios, ?\Closure $record = null): array
    {
        $all = [];

        foreach ($scenarios as $name => $params) {
            $raw = $this->request($url, $passkey, $params);
            $offers = self::offers($raw, $params);

            if ($record !== null) {
                $record((string) $name, $params, $raw, $offers);
            }

            if (array_filter($offers, fn (array $offer): bool => $offer['valid']) === []) {
                throw new SimulationUnavailable('Nessuna offerta valida'.(count($scenarios) > 1 ? " nello scenario {$name}" : '').'.');
            }

            $all = [...$all, ...$offers];
        }

        return ['offers' => $all, 'summary' => self::summary($all) ?? throw new SimulationUnavailable('Nessuna offerta valida.')];
    }

    /**
     * Riepilogo delle offerte valide (gli errori si scartano): realizzo medio ponderato, massimo e minimo con la banca.
     * Il peso di ogni offerta è la sua provvigione; se manca a qualcuna la media è semplice.
     *
     * @param  list<array{valid: bool, erogato: float, bank: ?string, commission: ?float}>  $offers
     * @return array{count: int, average: float, weighted: bool, max: array{amount: float, bank: ?string}, min: array{amount: float, bank: ?string}}|null
     */
    public static function summary(array $offers): ?array
    {
        $valid = array_values(array_filter($offers, fn (array $offer): bool => $offer['valid']));

        if ($valid === []) {
            return null;
        }

        $weighted = array_reduce($valid, fn (bool $all, array $offer): bool => $all && ($offer['commission'] ?? 0) > 0, true);
        $weights = array_map(fn (array $offer): float => $weighted ? (float) $offer['commission'] : 1.0, $valid);
        $average = array_sum(array_map(fn (array $offer, float $weight): float => $offer['erogato'] * $weight, $valid, $weights)) / array_sum($weights);

        usort($valid, fn (array $a, array $b): int => $a['erogato'] <=> $b['erogato']);
        $min = $valid[0];
        $max = $valid[count($valid) - 1];

        return [
            'count' => count($valid),
            'average' => round($average, 2),
            'weighted' => $weighted,
            'max' => ['amount' => $max['erogato'], 'bank' => $max['bank']],
            'min' => ['amount' => $min['erogato'], 'bank' => $min['bank']],
        ];
    }

    private static function bank(\SimpleXMLElement $node): ?string
    {
        foreach (['Istituto/Nome', 'Nome_istituto', 'Istituto', 'Nome_banca', 'Banca', 'Finanziaria', 'Ente'] as $path) {
            $found = $node->xpath($path);
            $name = $found ? trim((string) $found[0]) : '';

            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }

    /** «12.345,67» (italiano) oppure «12345.67». */
    private static function number(string $value): float
    {
        $value = trim($value);

        return (float) (str_contains($value, ',') ? str_replace(',', '.', str_replace('.', '', $value)) : $value);
    }
}
