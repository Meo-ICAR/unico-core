<?php

namespace Unico\Core\Documents\Api;

use RuntimeException;
use Unico\Core\Clients\OpenApiCreditScoreService;
use Unico\Core\Models\Document;

/** Credit score da Openapi. Finché il servizio è simulato non produce nessun file. */
class CreditScoreFetcher implements DocumentApiFetcher
{
    use ResolvesDocumentSubject;

    public function __construct(private OpenApiCreditScoreService $service) {}

    public function key(): string
    {
        return 'openapi_creditscore';
    }

    public function label(): string
    {
        return 'Openapi · credit score';
    }

    public function fetch(Document $document): DocumentApiResult
    {
        try {
            $result = $this->service->request($this->taxCodeOf($document));
        } catch (RuntimeException $e) {
            return DocumentApiResult::failed($e->getMessage());
        }

        return new DocumentApiResult(true, 'Richiesta simulata: nessuna chiamata effettuata, il documento va caricato.', simulated: true, meta: ['url' => $result['url'] ?? null]);
    }
}
