<?php

namespace Unico\Core\Bridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Autentica le chiamate del bridge con la chiave condivisa nell'intestazione X-Api-Key (`unico-core.bridge.api_key`, BPM_BRIDGE_API_KEY; in mancanza `services.bpm.api_key`, come le app che l'avevano già).
 * Senza chiave configurata il bridge è spento (503): mai aperto per dimenticanza.
 */
class VerifyBridgeKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) (config('unico-core.bridge.api_key') ?: config('services.bpm.api_key'));

        if ($expected === '') {
            Log::error('Bridge disabilitato: unico-core.bridge.api_key (BPM_BRIDGE_API_KEY) non configurata.');

            throw new HttpException(503, 'Servizio non configurato.');
        }

        if (! hash_equals($expected, (string) $request->header('X-Api-Key', ''))) {
            Log::warning('Accesso al bridge rifiutato: chiave API mancante o non valida.', ['path' => $request->path()]);

            throw new HttpException(401, 'Non autorizzato.');
        }

        $request->attributes->set('bridge_client', 'bridge');

        return $next($request);
    }
}
