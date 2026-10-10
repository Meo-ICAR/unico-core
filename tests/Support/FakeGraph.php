<?php

namespace Unico\Core\Tests\Support;

use Unico\Core\SharePoint\GraphTransport;

/** Microsoft Graph finto: registra le richieste e risponde come decide il test. */
class FakeGraph implements GraphTransport
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public int $failWith = 0;

    public function put(string $url, string $bytes, array $headers = [], bool $authenticated = true): array
    {
        $this->calls[] = ['method' => 'PUT', 'url' => $url, 'bytes' => strlen($bytes), 'headers' => $headers, 'auth' => $authenticated];

        if ($this->failWith) {
            return ['status' => $this->failWith, 'json' => null, 'body' => 'errore con dati'];
        }

        return ['status' => 200, 'json' => ['id' => 'ITEM1', 'eTag' => '"etag1"', 'webUrl' => 'https://tenant.sharepoint.com/sites/x/Documenti/file.pdf'], 'body' => ''];
    }

    public function post(string $url, array $data): array
    {
        $this->calls[] = ['method' => 'POST', 'url' => $url, 'data' => $data];

        return ['status' => 200, 'json' => ['uploadUrl' => 'https://upload.example/session1'], 'body' => ''];
    }
}
