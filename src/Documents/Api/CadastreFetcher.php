<?php

namespace Unico\Core\Documents\Api;

use RuntimeException;
use Unico\Core\Clients\OpenApiCadastreService;
use Unico\Core\Models\Document;

/** Dati catastali da Openapi (codice fiscale e indirizzo del proprietario). Finché il servizio è simulato non produce nessun file. */
class CadastreFetcher implements DocumentApiFetcher
{
    use ResolvesDocumentSubject;

    public function __construct(private OpenApiCadastreService $service) {}

    public function key(): string
    {
        return 'openapi_catasto';
    }

    public function label(): string
    {
        return 'Openapi · catasto';
    }

    public function fetch(Document $document): DocumentApiResult
    {
        $address = (string) ($document->documentable?->getAttribute('address') ?? $document->documentable?->getAttribute('indirizzo') ?? '');

        try {
            $this->service->lookup($this->taxCodeOf($document), $address);
        } catch (RuntimeException $e) {
            return DocumentApiResult::failed($e->getMessage());
        }

        return new DocumentApiResult(true, 'Richiesta simulata: nessuna chiamata effettuata, il documento va caricato.', simulated: true);
    }
}
