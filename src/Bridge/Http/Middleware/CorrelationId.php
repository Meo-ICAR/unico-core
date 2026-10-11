<?php

namespace Unico\Core\Bridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identificativo che attraversa le app: si riprende `X-Correlation-Id` della richiesta (o se ne crea uno), finisce nel contesto dei
 * log e nella risposta. Chi chiama un'altra app lo rimanda, così una catena unicobpm → app si segue in tutti i log.
 */
class CorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->header('X-Correlation-Id', '');
        $id = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $id) ? $id : (string) Str::uuid();

        $request->attributes->set('correlation_id', $id);
        Log::withContext(['correlation_id' => $id]);

        return tap($next($request), fn (Response $response) => $response->headers->set('X-Correlation-Id', $id));
    }
}
