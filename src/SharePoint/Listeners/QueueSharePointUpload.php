<?php

namespace Unico\Core\SharePoint\Listeners;

use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Unico\Core\Enums\SyncStatus;
use Unico\Core\Models\Document;
use Unico\Core\SharePoint\Jobs\UploadDocumentToSharePoint;
use Unico\Core\SharePoint\SharePointUploader;

/** Al caricamento di un file su un Document mette in coda l'invio a SharePoint. Senza credenziali il documento resta locale. */
class QueueSharePointUpload
{
    public function __construct(private SharePointUploader $uploader) {}

    public function handle(MediaHasBeenAddedEvent $event): void
    {
        $document = $event->media->model;

        if (! $document instanceof Document || ! in_array($event->media->collection_name, [Document::COLLECTION, Document::COLLECTION_SIGNED], true) || ! $this->uploader->isConfigured()) {
            return;
        }

        if ($document->company && ! $document->company->usesSharePointStorage()) {
            return;
        }

        $document->forceFill(['sync_status' => SyncStatus::LOCAL->value, 'app_id' => null])->saveQuietly();

        UploadDocumentToSharePoint::dispatch($document)->afterCommit();
    }
}
