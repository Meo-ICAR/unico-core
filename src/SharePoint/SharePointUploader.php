<?php

namespace Unico\Core\SharePoint;

use RuntimeException;

/** Carica file in un drive SharePoint con Microsoft Graph (porta di unicooam). Le cartelle mancanti le crea Graph. */
class SharePointUploader
{
    /** Oltre questa soglia Graph richiede una upload session. */
    public const SIMPLE_UPLOAD_LIMIT = 4 * 1024 * 1024;

    /** Multiplo di 320 KiB, come richiesto da Graph per i blocchi. */
    public const CHUNK_SIZE = 10 * 327680;

    public function __construct(private GraphTransport $http) {}

    public function isConfigured(): bool
    {
        foreach (['drive_id', 'tenant_id', 'client_id', 'client_secret'] as $key) {
            if (blank(config("unico-core.sharepoint.{$key}"))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $folders  cartelle sotto la radice del drive
     * @return array{id: string, etag: ?string, web_url: ?string, drive_id: string}
     */
    public function upload(string $absolutePath, array $folders, string $fileName): array
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException("File locale non trovato: {$absolutePath}");
        }

        $remote = $this->remotePath($folders, $fileName);
        $item = filesize($absolutePath) <= self::SIMPLE_UPLOAD_LIMIT
            ? $this->simpleUpload($absolutePath, $remote)
            : $this->sessionUpload($absolutePath, $remote);

        return [
            'id' => (string) $item['id'],
            'etag' => $item['eTag'] ?? null,
            'web_url' => $item['webUrl'] ?? null,
            'drive_id' => $this->driveId(),
        ];
    }

    /** Toglie i caratteri che SharePoint non accetta nei nomi di file e cartelle. */
    public function sanitize(string $name): string
    {
        $clean = trim((string) preg_replace('/[\\\\\/:*?"<>|#%]+/', '-', $name), " .\t\n\r");

        return $clean === '' ? '_' : mb_substr($clean, 0, 120);
    }

    /** @param list<string> $folders */
    public function remotePath(array $folders, string $fileName): string
    {
        $segments = array_map(
            fn (string $s) => rawurlencode($this->sanitize($s)),
            [...array_values(array_filter($folders, fn ($f) => $f !== '')), $fileName],
        );

        return implode('/', $segments);
    }

    /** @return array<string,mixed> */
    private function simpleUpload(string $path, string $remote): array
    {
        $response = $this->http->put($this->itemUrl($remote).':/content?@microsoft.graph.conflictBehavior=rename', (string) file_get_contents($path));

        if ($response['status'] >= 400) {
            throw new RuntimeException("Upload SharePoint fallito (HTTP {$response['status']})");
        }

        return $response['json'] ?? [];
    }

    /** @return array<string,mixed> */
    private function sessionUpload(string $path, string $remote): array
    {
        $session = $this->http->post($this->itemUrl($remote).':/createUploadSession', [
            'item' => ['@microsoft.graph.conflictBehavior' => 'rename'],
        ]);

        if ($session['status'] >= 400) {
            throw new RuntimeException("Upload session fallita (HTTP {$session['status']})");
        }

        $uploadUrl = (string) ($session['json']['uploadUrl'] ?? '');
        $size = filesize($path);
        $handle = fopen($path, 'rb');
        $item = [];

        try {
            for ($offset = 0; $offset < $size; $offset += self::CHUNK_SIZE) {
                $chunk = (string) fread($handle, self::CHUNK_SIZE);
                $end = $offset + strlen($chunk) - 1;

                $response = $this->http->put($uploadUrl, $chunk, ['Content-Range' => "bytes {$offset}-{$end}/{$size}"], authenticated: false);

                if ($response['status'] >= 400) {
                    throw new RuntimeException("Upload blocco fallito (HTTP {$response['status']})");
                }

                $item = $response['json'] ?? [];
            }
        } finally {
            fclose($handle);
        }

        if (empty($item['id'])) {
            throw new RuntimeException('Upload session terminata senza elemento creato.');
        }

        return $item;
    }

    private function itemUrl(string $remote): string
    {
        return "https://graph.microsoft.com/v1.0/drives/{$this->driveId()}/root:/{$remote}";
    }

    private function driveId(): string
    {
        return (string) config('unico-core.sharepoint.drive_id');
    }
}
