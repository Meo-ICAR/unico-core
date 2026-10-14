<?php

namespace Unico\Core\Console;

use Illuminate\Console\Command;
use Unico\Core\Enums\SyncStatus;
use Unico\Core\Models\Document;
use Unico\Core\SharePoint\Jobs\UploadDocumentToSharePoint;

class SharePointRetryCommand extends Command
{
    protected $signature = 'unico-core:sharepoint-retry {--local : Include anche i documenti rimasti solo locali}';

    protected $description = 'Rimette in coda i documenti con upload su SharePoint fallito';

    public function handle(): int
    {
        $statuses = [SyncStatus::FAILED->value, ...($this->option('local') ? [SyncStatus::LOCAL->value] : [])];

        $count = 0;

        Document::query()
            ->whereIn('sync_status', $statuses)
            ->whereHas('media', fn ($q) => $q->whereIn('collection_name', [Document::COLLECTION, Document::COLLECTION_SIGNED]))
            ->each(function (Document $document) use (&$count): void {
                UploadDocumentToSharePoint::dispatch($document);
                $count++;
            });

        $this->info("{$count} documenti rimessi in coda.");

        return self::SUCCESS;
    }
}
