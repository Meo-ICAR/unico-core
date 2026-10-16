<?php

namespace Unico\Core\Http\Controllers;

use Unico\Core\Models\SignatureRequest;
use Unico\Core\Signature\Jobs\ReconcileSignatureRequest;
use Unico\Core\Signature\SignatureProviderManager;
use Unico\Core\Signature\SignatureRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * Accetta solo i webhook del provider attivo (`signature.driver`); `fake` mai in produzione.
 * Dopo un cambio di driver i webhook del vecchio provider vengono rifiutati e le vecchie
 * richieste aperte sono coperte da `signature:sync`.
 */
class SignatureWebhookController
{
    public function __invoke(Request $request, string $provider, SignatureProviderManager $providers, SignatureRequestService $service): Response
    {
        if ($provider !== config('unico-core.signature.driver') || ($provider === 'fake' && config('app.env') === 'production')) {
            return new Response('', 404);
        }

        try {
            $signatureProvider = $providers->provider($provider);
        } catch (InvalidArgumentException) {
            return new Response('', 404);
        }

        $event = $signatureProvider->parseWebhook($request);

        if ($event === null) {
            return new Response('', 401);
        }

        if ($event->type === 'ignored') {
            return new Response('', 204);
        }

        $signatureRequest = $service->applyWebhook($provider, $event);

        if ($signatureRequest === null) {
            return new Response('', 204);
        }

        // Un plico ha una richiesta per documento: ognuna si riallinea alla busta.
        SignatureRequest::query()
            ->where('provider', $provider)
            ->where('provider_ref', $signatureRequest->provider_ref)
            ->pluck('id')
            ->each(fn (int $id) => ReconcileSignatureRequest::dispatch($id));

        return new Response('', 202);
    }
}
