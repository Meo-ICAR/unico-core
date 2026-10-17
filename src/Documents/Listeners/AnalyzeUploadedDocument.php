<?php

namespace Unico\Core\Documents\Listeners;

use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Unico\Core\Documents\Jobs\AnalyzeDocument;
use Unico\Core\Models\Document;

/** Al caricamento di un file su un Document (collection `documents` o `signed`) mette in coda l'analisi. */
class AnalyzeUploadedDocument
{
    private static bool $suspended = false;

    /**
     * Esegue la funzione senza far partire l'analisi automatica: chi carica il file la lancia da sé
     * (con i tipi candidati che conosce, ad esempio i documenti richiesti dal task).
     *
     * @template T
     *
     * @param  \Closure():T  $callback
     * @return T
     */
    public static function suspendedWhile(\Closure $callback): mixed
    {
        $previous = self::$suspended;
        self::$suspended = true;

        try {
            return $callback();
        } finally {
            self::$suspended = $previous;
        }
    }

    public function handle(MediaHasBeenAddedEvent $event): void
    {
        if (self::$suspended) {
            return;
        }

        $document = $event->media->model;

        // Il file corretto di un documento respinto è una nuova versione: il file va sul nuovo record e il vecchio resta com'è.
        if ($document instanceof Document && $event->media->collection_name === Document::COLLECTION) {
            $version = $document->versionForUpload($event->media->getKey());

            if ($version->getKey() !== $document->getKey()) {
                $event->media->move($version, Document::COLLECTION);
                $document = $version;
            }
        }

        if ($document instanceof Document && in_array($event->media->collection_name, [Document::COLLECTION, Document::COLLECTION_SIGNED], true)) {
            config('unico-core.documents.analysis.sync')
                ? AnalyzeDocument::dispatchSync($document->fresh())
                : AnalyzeDocument::dispatch($document->fresh())->afterCommit();
        }
    }
}
