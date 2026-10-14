<?php

namespace Unico\Core\SharePoint;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Trasporto reale: token applicativo (client credentials) in cache, richieste con il client HTTP di Laravel. */
class HttpGraphTransport implements GraphTransport
{
    public function put(string $url, string $bytes, array $headers = [], bool $authenticated = true): array
    {
        $request = $authenticated ? Http::withToken($this->token()) : Http::withHeaders([]);

        return $this->normalize($request->withHeaders($headers)->withBody($bytes, 'application/octet-stream')->timeout(120)->put($url));
    }

    public function post(string $url, array $data): array
    {
        return $this->normalize(Http::withToken($this->token())->post($url, $data));
    }

    private function normalize($response): array
    {
        return ['status' => $response->status(), 'json' => $response->json(), 'body' => $response->body()];
    }

    private function token(): string
    {
        return Cache::remember('unico-core.sharepoint.token', 3000, function (): string {
            $tenant = config('unico-core.sharepoint.tenant_id');
            $response = Http::asForm()->post("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
                'client_id' => config('unico-core.sharepoint.client_id'),
                'client_secret' => config('unico-core.sharepoint.client_secret'),
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]);

            if ($response->failed()) {
                throw new RuntimeException("Errore token SharePoint (HTTP {$response->status()})");
            }

            return (string) $response->json('access_token');
        });
    }
}
