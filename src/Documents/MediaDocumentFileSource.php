<?php

namespace Unico\Core\Documents;

use Illuminate\Support\Facades\Storage;
use Unico\Core\Documents\Contracts\DocumentFileSource;
use Unico\Core\Models\Document;

/** Prende il file dalla media library: prima la versione firmata, poi l'originale. */
class MediaDocumentFileSource implements DocumentFileSource
{
    public function fetch(Document $document): ?DocumentFile
    {
        $media = $document->getFirstMedia(Document::COLLECTION_SIGNED) ?? $document->getFirstMedia(Document::COLLECTION);

        if ($media === null) {
            return null;
        }

        $disk = Storage::disk($media->disk);
        $path = $media->getPathRelativeToRoot();

        // File registrato ma sparito dal disco: contenuto vuoto, che il verificatore segnala come non apribile.
        $bytes = $disk->exists($path) ? (string) $disk->get($path) : '';

        return new DocumentFile($bytes, (string) $media->mime_type, (string) $media->file_name);
    }
}
