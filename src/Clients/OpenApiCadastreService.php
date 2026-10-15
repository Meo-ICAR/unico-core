<?php

namespace Unico\Core\Clients;

use RuntimeException;

/**
 * Dati catastali da Openapi.it (codice fiscale + indirizzo). Come il credit score, per ora è simulato: non parte nessuna
 * chiamata e la risposta è marcata `live = false`. Con `unico-core.openapi.catasto_live` il servizio dice che la chiamata
 * reale non è ancora disponibile (endpoint e risposta vanno ricavati dalla definizione OpenAPI del prodotto Catasto).
 */
class OpenApiCadastreService
{
    /** @return array{live: bool, tax_code: string, parcels: list<array{foglio: ?string, particella: ?string, subalterno: ?string, categoria: ?string, rendita: ?float}>} */
    public function lookup(string $taxCode, string $address): array
    {
        $taxCode = strtoupper(preg_replace('/\s+/', '', $taxCode) ?? '');
        if ($taxCode === '' || trim($address) === '') {
            throw new RuntimeException('Codice fiscale o indirizzo mancante.');
        }

        if (config('unico-core.openapi.catasto_live')) {
            throw new RuntimeException('La chiamata reale al Catasto non è ancora attiva.');
        }

        return ['live' => false, 'tax_code' => $taxCode, 'parcels' => [['foglio' => '1', 'particella' => '1', 'subalterno' => '1', 'categoria' => 'A/2', 'rendita' => 500.0]]];
    }
}
