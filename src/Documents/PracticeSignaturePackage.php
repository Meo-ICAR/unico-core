<?php

namespace Unico\Core\Documents;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Unico\Core\Documents\Listeners\AnalyzeUploadedDocument;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\Pratica;
use Unico\Core\Signature\FakeSignatureProvider;
use Unico\Core\Signature\SignatureDemo;
use Unico\Core\Models\SignatureRequest;
use Unico\Core\Pdf\ModulePrinter;
use Unico\Core\Pdf\PdfFormException;
use Unico\Core\Pdf\PracticeModuleData;
use Unico\Core\Pdf\PrintReadiness;
use Unico\Core\Signature\Exceptions\SignatureRequestException;
use Unico\Core\Signature\SignatureProviderManager;
use Unico\Core\Signature\SignatureRequestService;
use Unico\Core\Signature\SignerDefaults;

/**
 * Modulistica di una pratica da stampare e far firmare con OTP: per ogni tipo documento con un modulo PDF controlla che i dati
 * necessari ci siano, stampa il modulo compilato, lo archivia sulla pratica e lo invia al provider di firma (Yousign) ai firmatari
 * (cliente e collaboratore). Stato di ogni modulo: senza_modulo, dati_mancanti, pronto, stampato, inviato, firmato, rifiutato.
 */
class PracticeSignaturePackage
{
    /** Nomi leggibili delle chiavi dati più comuni, per dire all'agente cosa manca. */
    private const LABELS = [
        'client.name' => 'cognome / ragione sociale', 'client.first_name' => 'nome', 'client.tax_code' => 'codice fiscale',
        'client.nominativo' => 'cognome e nome', 'client.birth_date' => 'data di nascita', 'client.birth_place' => 'luogo di nascita',
        'client.phone' => 'cellulare', 'client.email' => 'email', 'client.vat_number' => 'partita IVA',
        'client.address.street' => 'indirizzo di residenza', 'client.address.zip_code' => 'CAP', 'client.address.city' => 'città di residenza',
        'client.address.province' => 'provincia', 'client.address.line' => 'indirizzo', 'agent.name' => 'nome del collaboratore',
        'agent.branch_city' => 'sede del collaboratore', 'pratica.amount' => 'importo', 'pratica.denominazione_prodotto' => 'prodotto',
        'document.identity.docnumber' => 'numero del documento d\'identità', 'document.identity.expires_at' => 'scadenza del documento',
    ];

    public function __construct(
        private ModulePrinter $printer,
        private PrintReadiness $readiness,
        private PracticeModuleData $data,
        private SignatureRequestService $signatures,
    ) {}

    /**
     * Stato di ciascun modulo: per id del tipo, [state, label, missing (nomi leggibili), request (stato della richiesta)].
     *
     * @param  Collection<int, DocumentType>  $types
     * @return array<int, array{state: string, name: string, missing: list<string>, document: ?Document}>
     */
    public function status(Pratica $pratica, Collection $types): array
    {
        $status = [];

        foreach ($types as $type) {
            $document = $this->document($pratica, $type, create: false);
            $request = $document?->signatureRequests()->latest('id')->first();
            $missing = $this->missing($pratica, $type);

            $state = match (true) {
                $type->pdfModule === null => 'senza_modulo',
                $document?->is_signed === true => 'firmato',
                $request !== null && $request->status->value === 'signed' => 'firmato',
                $request !== null && in_array($request->status->value, ['pending', 'sent'], true) => 'inviato',
                $request !== null && in_array($request->status->value, ['declined', 'expired', 'cancelled', 'failed'], true) => 'rifiutato',
                $document?->getFirstMedia(Document::COLLECTION) !== null => 'stampato',
                $missing !== [] => 'dati_mancanti',
                default => 'pronto',
            };

            $status[$type->getKey()] = ['state' => $state, 'name' => (string) $type->name, 'missing' => $missing, 'document' => $document];
        }

        return $status;
    }

    /**
     * Stampa i moduli pronti e li invia per la firma. Ritorna l'esito di ciascuno: [name, ok, message].
     *
     * @param  Collection<int, DocumentType>  $types
     * @return list<array{name: string, ok: bool, message: string}>
     */
    public function send(Pratica $pratica, Collection $types, ?Authenticatable $user = null): array
    {
        $results = [];

        foreach ($this->status($pratica, $types) as $typeId => $entry) {
            $type = $types->firstWhere('id', $typeId);
            $name = $entry['name'];

            if (in_array($entry['state'], ['firmato', 'inviato'], true)) {
                $results[] = ['name' => $name, 'ok' => true, 'message' => $entry['state'] === 'firmato' ? 'già firmato' : 'già inviato per la firma'];

                continue;
            }

            if ($entry['state'] === 'senza_modulo') {
                $results[] = ['name' => $name, 'ok' => false, 'message' => 'modulo PDF non disponibile: da stampare e far firmare a parte'];

                continue;
            }

            if ($entry['missing'] !== []) {
                $results[] = ['name' => $name, 'ok' => false, 'message' => 'mancano: '.implode(', ', $entry['missing'])];

                continue;
            }

            try {
                $document = $this->print($pratica, $type);
                $slots = $type->pdfModule?->signature_slots ?? [];

                if ($slots === []) {
                    $results[] = ['name' => $name, 'ok' => true, 'message' => 'stampato (non richiede firma elettronica)'];

                    continue;
                }

                // Solo i firmatari che il modulo prevede (cliente, collaboratore): se uno non si risolve lo si dice.
                $wanted = array_values(array_unique(array_column($slots, 'slot')));
                $signers = array_intersect_key(SignerDefaults::for($document), array_flip($wanted));

                if (($absent = array_values(array_diff($wanted, array_keys($signers)))) !== []) {
                    throw new SignatureRequestException('Firmatario non disponibile: '.implode(', ', $absent).'.');
                }

                $this->signatures->send($document, array_values($signers), $user);
                $results[] = ['name' => $name, 'ok' => true, 'message' => 'inviato per la firma con OTP'];
            } catch (PdfFormException|SignatureRequestException $e) {
                $results[] = ['name' => $name, 'ok' => false, 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    /** Aggiorna lo stato delle richieste aperte dal provider (utile senza webhook). */
    /**
     * Simulazione (solo in modalità demo): il cliente ha digitato il codice OTP e firma tutte le richieste aperte della modulistica.
     *
     * @return int richieste firmate
     */
    public function simulaFirmaOtp(Pratica $pratica, Collection $types): int
    {
        if (! SignatureDemo::attiva()) {
            return 0;
        }

        $firmate = 0;
        $provider = app(SignatureProviderManager::class)->provider('fake');

        foreach ($this->status($pratica, $types) as $entry) {
            $request = $entry['document']?->signatureRequests()->latest('id')->first();

            if (! $request instanceof SignatureRequest || ! $request->isOpen() || ! $provider instanceof FakeSignatureProvider) {
                continue;
            }

            $provider->completeAll($request->provider_ref);
            $this->signatures->reconcile($request);
            $firmate++;
        }

        return $firmate;
    }

    public function refresh(Pratica $pratica, Collection $types): void
    {
        foreach ($this->status($pratica, $types) as $entry) {
            $request = $entry['document']?->signatureRequests()->latest('id')->first();

            if ($request instanceof SignatureRequest) {
                try {
                    $this->signatures->reconcile($request);
                } catch (SignatureRequestException) {
                    // lo stato resta quello registrato
                }
            }
        }
    }

    /**
     * Dati obbligatori del modulo non ancora disponibili, con nomi leggibili.
     *
     * @return list<string>
     */
    public function missing(Pratica $pratica, DocumentType $type): array
    {
        $module = $type->pdfModule;

        if ($module === null || ! $module->is_active) {
            return [];
        }

        $module->loadMissing('pdfModuleFields');
        $required = $module->pdfModuleFields->filter->is_required;
        $keys = $this->data->for($pratica)->missingAmong($required->flatMap->referencedKeys());

        return array_map(fn (string $key): string => self::LABELS[$key] ?? $key, $keys);
    }

    /** Il documento della pratica per quel modulo, con il PDF compilato al posto di quello precedente non firmato. */
    private function print(Pratica $pratica, DocumentType $type): Document
    {
        $document = $this->document($pratica, $type, create: true);
        $pdf = $this->printer->print($pratica, $type->pdfModule);

        AnalyzeUploadedDocument::suspendedWhile(function () use ($document, $pdf, $type): void {
            $document->clearMediaCollection(Document::COLLECTION);
            $document->addMediaFromString($pdf)
                ->usingFileName((Str::slug((string) $type->name) ?: 'modulo').'.pdf')
                ->toMediaCollection(Document::COLLECTION);
        });

        $document->forceFill(['status' => DocumentStatus::UPLOADED, 'is_printable' => true])->saveQuietly();

        return $document->fresh();
    }

    private function document(Pratica $pratica, DocumentType $type, bool $create): ?Document
    {
        $query = Document::query()
            ->where('documentable_type', 'pratica')
            ->where('documentable_id', (string) $pratica->getKey())
            ->where('document_type_id', $type->getKey())
            ->latest('id');

        $document = $query->first();

        if ($document !== null || ! $create) {
            return $document;
        }

        return Document::query()->create([
            'company_id' => $pratica->company_id,
            'documentable_type' => 'pratica',
            'documentable_id' => (string) $pratica->getKey(),
            'document_type_id' => $type->getKey(),
            'name' => $type->name,
            'status' => DocumentStatus::PENDING,
        ]);
    }
}
