<?php

namespace Unico\Core\Clients;

use RuntimeException;

/**
 * Credit score da Openapi.it: GET {creditscore_url}/IT-creditscore-top/{codiceFiscale}
 * con Accept: application/json e Authorization: Bearer {token}.
 *
 * Per ora la richiesta è simulata: non parte nessuna chiamata (e non si paga) e il risultato dice solo che si sta
 * attivando il servizio, con l'indirizzo che verrà interrogato. Con `unico-core.openapi.creditscore_live` il servizio
 * non è ancora disponibile e lo dice, invece di fingere una risposta.
 */
class OpenApiCreditScoreService
{
    /** Indirizzo della richiesta per quel codice fiscale o partita IVA. */
    public function endpoint(string $taxCode): string
    {
        return rtrim((string) config('unico-core.openapi.creditscore_url'), '/').'/IT-creditscore-top/'.rawurlencode(strtoupper(trim($taxCode)));
    }

    /**
     * @return array{simulated: bool, method: string, url: string, tax_code: string, score: null}
     */
    public function request(string $taxCode): array
    {
        $taxCode = strtoupper(trim($taxCode));

        if ($taxCode === '') {
            throw new RuntimeException('Codice fiscale o partita IVA mancante.');
        }

        if (config('unico-core.openapi.creditscore_live')) {
            throw new RuntimeException('La chiamata reale al credit score non è ancora attiva.');
        }

        return [
            'simulated' => true,
            'method' => 'GET',
            'url' => $this->endpoint($taxCode),
            'tax_code' => $taxCode,
            'score' => null,
        ];
    }
}
