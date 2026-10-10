<?php

namespace Unico\Core\SharePoint;

use Throwable;
use Unico\Core\Enums\SyncStatus;
use Unico\Core\Models\Document;

/**
 * Porta su SharePoint il file (Spatie) di un documento e allinea `document_url`, `app_id`, `app_drive_id`, `app_etag`,
 * `source_app` e `sync_status`. Lo stesso schema di colonne usato da Drive: un documento sta in un solo cloud alla volta.
 */
class DocumentSharePointSync
{
    public function __construct(private SharePointUploader $uploader) {}

    /** Carica il file della collection `documents`. Non fa nulla se manca la configurazione, il file o se è già sincronizzato. */
    public function sync(Document $document): bool
    {
        $media = $document->getFirstMedia(Document::COLLECTION);

        if ($media === null || ! $this->uploader->isConfigured()) {
            return false;
        }

        if ($document->sync_status === SyncStatus::SYNCED->value && filled($document->app_id) && $document->source_app === 'sharepoint') {
            return false;
        }

        $this->upload($document, $media->getPath(), (string) $media->file_name);

        return true;
    }

    public function upload(Document $document, string $path, string $fileName): void
    {
        $document->forceFill(['sync_status' => SyncStatus::SYNCING->value])->saveQuietly();

        $result = $this->uploader->upload($path, $this->folders($document), $fileName);
        $webUrl = $result['web_url'];

        $document->forceFill([
            'sync_status' => SyncStatus::SYNCED->value,
            'source_app' => 'sharepoint',
            'app_id' => $result['id'],
            'app_drive_id' => $result['drive_id'],
            'app_etag' => $result['etag'],
            // document_url è varchar(255): l'URL completo resta sempre in metadata.web_url; se non ci sta
            // si svuota, così `resolved_url` non punta più al vecchio cloud.
            'document_url' => $webUrl !== null && strlen($webUrl) <= 255 ? $webUrl : null,
            'metadata' => array_merge($document->metadata ?? [], ['web_url' => $webUrl, 'sync_error' => null]),
        ])->saveQuietly();
    }

    public function markFailed(Document $document, Throwable $e): void
    {
        $document->forceFill([
            'sync_status' => SyncStatus::FAILED->value,
            'metadata' => array_merge($document->metadata ?? [], ['sync_error' => mb_substr($e->getMessage(), 0, 500)]),
        ])->saveQuietly();
    }

    /** @return list<string> radice configurata e cartella del soggetto a cui è legato il documento */
    public function folders(Document $document): array
    {
        $owner = $document->documentable;
        $label = $owner?->name ?? $owner?->nome ?? $owner?->ragione_sociale
            ?? trim(($document->documentable_type ?? 'documento').'-'.($document->documentable_id ?? $document->id), '-');

        return [(string) config('unico-core.sharepoint.upload_root', ''), $this->uploader->sanitize((string) $label)];
    }
}
