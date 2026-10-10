<?php

namespace Unico\Core\SharePoint;

/** Chiamate HTTP verso Microsoft Graph. Sostituibile nelle prove. */
interface GraphTransport
{
    /**
     * PUT di un contenuto binario. Con `$authenticated=false` non invia il bearer (gli uploadUrl sono già firmati).
     *
     * @param  array<string,string>  $headers
     * @return array{status: int, json: array<string,mixed>|null, body: string}
     */
    public function put(string $url, string $bytes, array $headers = [], bool $authenticated = true): array;

    /** @return array{status: int, json: array<string,mixed>|null, body: string} */
    public function post(string $url, array $data): array;
}
