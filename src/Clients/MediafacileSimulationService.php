<?php

namespace Unico\Core\Clients;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

/**
 * Servizio di simulazione Mediafacile: POST in form (con la `passkey`) e risposta XML, un'offerta per banca.
 * La risposta vera è un fmresultset di FileMaker (un `record` con i `field` per offerta); si legge anche un XML
 * semplice con un elemento per offerta. Un'offerta è valida se `Errore` vale 2.
 */
class MediafacileSimulationService
{
    public function __construct(private int $timeout = 0) {}

    /**
     * Invia la simulazione e restituisce il corpo della risposta.
     *
     * @param  array<string,string>  $params  senza passkey
     *
     * @throws SimulationUnavailable
     */
    public function request(string $url, string $passkey, array $params): string
    {
        $timeout = $this->timeout ?: (int) config('finanziamento.quote.timeout', 15);

        try {
            $response = Http::timeout($timeout)->asForm()->post($url, ['passkey' => $passkey] + $params);
        } catch (ConnectionException $e) {
            throw new SimulationUnavailable('Servizio di simulazione non raggiungibile: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new SimulationUnavailable("Il servizio di simulazione ha risposto {$response->status()}.");
        }

        return $response->body();
    }

    /**
     * Una voce per ogni offerta, con importo erogato, banca e provvigione.
     *
     * @return list<array{valid: bool, erogato: float, bank: ?string, commission: ?float}>
     *
     * @throws SimulationUnavailable
     */
    public static function offers(string $raw): array
    {
        $offers = [];
        foreach (self::records(self::load($raw)) as $fields) {
            if (! array_key_exists('Importo_erogato', $fields)) {
                continue;
            }

            $offers[] = [
                'valid' => trim($fields['Errore'] ?? '') === '2',
                'erogato' => self::number($fields['Importo_erogato']),
                'bank' => self::filled($fields['Denominazione_istituto'] ?? $fields['Istituto'] ?? null),
                'commission' => isset($fields['Importo_provvigione']) && trim($fields['Importo_provvigione']) !== '' ? self::number($fields['Importo_provvigione']) : null,
            ];
        }

        return $offers;
    }

    /**
     * @return list<array{valid: bool, erogato: float}>
     *
     * @throws SimulationUnavailable
     */
    public static function parse(string $raw): array
    {
        return array_map(fn (array $offer): array => ['valid' => $offer['valid'], 'erogato' => $offer['erogato']], self::offers($raw));
    }

    /**
     * Realizzo delle offerte valide: medio (ponderato sulla provvigione se c'è, altrimenti semplice), massimo e minimo con la banca.
     *
     * @param  list<array{valid: bool, erogato: float, bank?: ?string, commission?: ?float}>  $offers
     * @return array{count: int, weighted: bool, average: float, max: array{amount: float, bank: ?string}, min: array{amount: float, bank: ?string}}|null
     */
    public static function summary(array $offers): ?array
    {
        $valid = array_values(array_filter($offers, fn (array $offer): bool => $offer['valid']));
        if ($valid === []) {
            return null;
        }

        $max = $min = $valid[0];
        $weight = $weighted = $total = 0.0;
        foreach ($valid as $offer) {
            $max = $offer['erogato'] > $max['erogato'] ? $offer : $max;
            $min = $offer['erogato'] < $min['erogato'] ? $offer : $min;
            $commission = max(0.0, (float) ($offer['commission'] ?? 0));
            $weight += $commission;
            $weighted += $offer['erogato'] * $commission;
            $total += $offer['erogato'];
        }

        return [
            'count' => count($valid),
            'weighted' => $weight > 0,
            'average' => $weight > 0 ? $weighted / $weight : $total / count($valid),
            'max' => ['amount' => $max['erogato'], 'bank' => $max['bank'] ?? null],
            'min' => ['amount' => $min['erogato'], 'bank' => $min['bank'] ?? null],
        ];
    }

    private static function load(string $raw): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new SimulationUnavailable('Risposta del servizio di simulazione non valida.');
        }

        return $xml;
    }

    /** @return list<array<string,string>> i campi di ogni offerta */
    private static function records(SimpleXMLElement $xml): array
    {
        $records = [];

        foreach ($xml->xpath("//*[local-name()='record']") ?: [] as $record) {
            $fields = [];
            foreach ($record->xpath("*[local-name()='field']") ?: [] as $field) {
                $fields[(string) $field['name']] = trim((string) ($field->xpath("*[local-name()='data']")[0] ?? ''));
            }
            $records[] = $fields;
        }

        if ($records !== []) {
            return $records;
        }

        foreach ($xml->xpath('//Importo_erogato/..') ?: [] as $node) {
            $fields = [];
            foreach ($node->children() as $name => $child) {
                $fields[$name] = trim((string) $child);
            }
            $fields['Istituto'] = trim((string) ($node->Istituto->Nome ?? $node->Istituto ?? ''));
            $records[] = $fields;
        }

        return $records;
    }

    private static function filled(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }

    /** «12.345,67» (italiano) oppure «12345.67». */
    private static function number(string $value): float
    {
        $value = trim($value);

        return (float) (str_contains($value, ',') ? str_replace(',', '.', str_replace('.', '', $value)) : $value);
    }
}
