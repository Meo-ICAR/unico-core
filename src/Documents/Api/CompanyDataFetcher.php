<?php

namespace Unico\Core\Documents\Api;

use RuntimeException;
use Unico\Core\Clients\OpenApiCompanyService;
use Unico\Core\Models\Document;

/** Dati societari da Openapi (IT-advanced), salvati come file JSON del documento (es. la visura camerale). */
class CompanyDataFetcher implements DocumentApiFetcher
{
    use ResolvesDocumentSubject;

    public function __construct(protected OpenApiCompanyService $service) {}

    public function key(): string
    {
        return 'openapi_company';
    }

    public function label(): string
    {
        return 'Openapi · dati societari';
    }

    public function fetch(Document $document): DocumentApiResult
    {
        $vat = $this->taxCodeOf($document);

        if ($vat === '') {
            return DocumentApiResult::failed('Manca la partita IVA del proprietario del documento.');
        }

        try {
            $result = $this->load($vat, $document);
        } catch (RuntimeException $e) {
            return DocumentApiResult::failed($e->getMessage());
        }

        return new DocumentApiResult(
            ok: true,
            message: 'Dati societari acquisiti: '.($result['company']['name'] ?? $vat),
            content: json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            fileName: 'dati-societari-'.$vat.'.json',
            meta: ['vat' => $vat, 'name' => $result['company']['name'] ?? null],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function load(string $vat, Document $document): array
    {
        return $this->service->fetchCompany($vat);
    }
}
