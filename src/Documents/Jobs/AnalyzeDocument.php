<?php

namespace Unico\Core\Documents\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Unico\Core\Documents\DocumentPipeline;
use Unico\Core\Models\Document;

/** Analizza un documento in coda. */
class AnalyzeDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public Document $document, public array $options = [])
    {
        $this->onQueue(config('unico-core.documents.analysis.queue', 'documents'));
    }

    public function handle(DocumentPipeline $pipeline): void
    {
        $pipeline->run($this->document, $this->options);
    }
}
