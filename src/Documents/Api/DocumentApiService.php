<?php

namespace Unico\Core\Documents\Api;

use Illuminate\Support\Facades\Event;
use Unico\Core\Documents\Listeners\AnalyzeUploadedDocument;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentType;

/**
 * Richiede via API i documenti il cui tipo ha un servizio (`document_types.api_service`): il file ricevuto sostituisce il
 * caricamento. I servizi sono i fetcher registrati; un'app può sostituirne uno registrandone un altro con la stessa chiave
 * (tag `unico-core.document-api-fetchers`).
 */
class DocumentApiService
{
    /** @var array<string, DocumentApiFetcher> */
    private array $fetchers = [];

    /**
     * @param  iterable<DocumentApiFetcher>  $fetchers
     */
    public function __construct(iterable $fetchers = [])
    {
        foreach ($fetchers as $fetcher) {
            $this->fetchers[$fetcher->key()] = $fetcher;
        }
    }

    /** @return array<string, string> chiave => etichetta */
    public function services(): array
    {
        return array_map(fn (DocumentApiFetcher $fetcher): string => $fetcher->label(), $this->fetchers);
    }

    public function supports(?DocumentType $type): bool
    {
        return $type !== null && filled($type->api_service) && isset($this->fetchers[$type->api_service]);
    }

    /**
     * Chiama il servizio del tipo documento. Con un file valido lo allega, segna il documento caricato ed emesso oggi.
     */
    public function request(Document $document): DocumentApiResult
    {
        $document->loadMissing('documentType', 'documentable');
        $type = $document->documentType;

        if (! $this->supports($type)) {
            return DocumentApiResult::failed('Per questo tipo di documento non c\'è un servizio API: va caricato.');
        }

        $service = (string) $type->api_service;
        $startedAt = microtime(true);
        $result = $this->fetchers[$service]->fetch($document);

        if ($result->hasFile()) {
            $this->store($document, $result, $service);
        }

        Event::dispatch(new DocumentApiRequested($document, $service, $result, $startedAt));

        return $result;
    }

    private function store(Document $document, DocumentApiResult $result, string $service): void
    {
        $path = tempnam(sys_get_temp_dir(), 'docapi');
        file_put_contents($path, $result->content);

        AnalyzeUploadedDocument::suspendedWhile(fn () => $document->addMedia($path)
            ->usingFileName($result->fileName)
            ->toMediaCollection(Document::COLLECTION));

        $document->forceFill([
            'status' => DocumentStatus::UPLOADED,
            'emitted_at' => today(),
            'channel' => 'api',
            'channel_ref' => $service,
            'metadata' => [...($document->metadata ?? []), 'api' => ['service' => $service, 'at' => now()->toIso8601String()] + $result->meta],
        ])->save();
    }
}
