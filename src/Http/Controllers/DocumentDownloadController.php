<?php

namespace Unico\Core\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Unico\Core\Models\Document;

class DocumentDownloadController
{
    /**
     * Scarica il file di un documento: la copia locale se c'è, altrimenti rimanda all'URL remoto.
     *
     * La rotta richiede l'utente autenticato e l'abilitazione `download` sul documento
     * (policy dell'app). Se l'app non ha una policy per `Document`, si usa solo
     * il filtro per tenant: il documento deve appartenere alla company dell'utente.
     */
    public function __invoke(Document $document): BinaryFileResponse|RedirectResponse
    {
        abort_unless($this->allowed($document), 403);

        $media = $document->getFirstMedia(Document::COLLECTION);

        if ($media !== null && is_file($media->getPath())) {
            return response()->download($media->getPath(), $media->file_name);
        }

        $url = $document->resolved_url;

        abort_if(blank($url), 404);

        return redirect()->away(str_starts_with($url, 'http') ? $url : "https://{$url}");
    }

    protected function allowed(Document $document): bool
    {
        if (Gate::getPolicyFor($document) !== null) {
            return Gate::allows('download', $document) || Gate::allows('view', $document);
        }

        $user = auth()->user();

        return $user !== null
            && ($document->company_id === null || $document->company_id === ($user->company_id ?? null));
    }
}
