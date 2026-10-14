<?php

namespace Unico\Core\SharePoint\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use Unico\Core\Models\Document;
use Unico\Core\SharePoint\DocumentSharePointSync;

/** Carica in coda il file di un documento su SharePoint. */
class UploadDocumentToSharePoint implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Document $document)
    {
        $this->onQueue(config('unico-core.sharepoint.queue', 'documents'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(DocumentSharePointSync $sync): void
    {
        $sync->sync($this->document);
    }

    public function failed(Throwable $e): void
    {
        app(DocumentSharePointSync::class)->markFailed($this->document, $e);
    }
}
