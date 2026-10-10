<?php

namespace Unico\Core\Documents\Listeners;

use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Unico\Core\Documents\Jobs\AnalyzeDocument;
use Unico\Core\Models\Document;

/** Al caricamento di un file su un Document (collection `documents` o `signed`) mette in coda l'analisi. */
class AnalyzeUploadedDocument
{
    public function handle(MediaHasBeenAddedEvent $event): void
    {
        $document = $event->media->model;

        if ($document instanceof Document && in_array($event->media->collection_name, [Document::COLLECTION, Document::COLLECTION_SIGNED], true)) {
            AnalyzeDocument::dispatch($document->fresh())->afterCommit();
        }
    }
}
