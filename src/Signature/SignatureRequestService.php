<?php

namespace Unico\Core\Signature;

use Unico\Core\Enums\SignatureRequestStatus;
use Unico\Core\Enums\SignerStatus;
use Unico\Core\Enums\SyncStatus;
use Unico\Core\Models\Document;
use Unico\Core\Models\SignatureRequest;
use Unico\Core\Events\SignatureCancelled;
use Unico\Core\Events\SignatureCompleted;
use Unico\Core\Events\SignatureSent;
use Unico\Core\Pdf\PdfFormException;
use Unico\Core\Pdf\PdfFormFiller;
use Unico\Core\Signature\Dto\EnvelopeData;
use Unico\Core\Signature\Dto\EnvelopeDocument;
use Unico\Core\Signature\Dto\EnvelopeSigner;
use Unico\Core\Signature\Dto\EnvelopeStatus;
use Unico\Core\Signature\Dto\SignatureEvent;
use Unico\Core\Signature\Dto\SignaturePlacement;
use Unico\Core\Signature\Exceptions\SignatureException;
use Unico\Core\Signature\Exceptions\SignatureRequestException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SignatureRequestService
{
    public function __construct(
        private SignatureProviderManager $providers,
        private PdfFormFiller $pdfFiller,
    ) {}

    /**
     * Invia il documento per la firma.
     *
     * @param  array<int, SignerInput>  $signers
     *
     * @throws SignatureRequestException
     */
    public function send(Document $document, array $signers, ?Authenticatable $user = null): SignatureRequest
    {
        return $this->sendBundle([$document], $signers, $user)[0];
    }

    /**
     * Invia più documenti in un'unica busta (plico): il firmatario riceve una sola richiesta e un solo codice OTP per firmarli
     * tutti. Ogni documento ha la sua richiesta locale (stato, firmatari, PDF firmato); le richieste condividono `provider_ref`.
     *
     * @param  array<int, Document>  $documents
     * @param  array<int, SignerInput>  $signers  uno per firmatario (slot) tra tutti i moduli del plico
     * @return array<int, SignatureRequest> nello stesso ordine dei documenti
     *
     * @throws SignatureRequestException
     */
    public function sendBundle(array $documents, array $signers, ?Authenticatable $user = null): array
    {
        $documents = array_values($documents);

        if ($documents === []) {
            throw new SignatureRequestException('Nessun documento da inviare per la firma.');
        }

        $bundle = count($documents) > 1;
        $originals = [];
        $slotRows = [];

        foreach ($documents as $index => $document) {
            $original = $this->readOriginalPdf($document);

            if ($original === null) {
                throw new SignatureRequestException($bundle ? "Il documento «{$document->name}» non ha un PDF da firmare." : 'Il documento non ha un PDF da firmare.');
            }

            $slots = $document->documentType?->pdfModule?->signature_slots ?? [];

            if ($slots === []) {
                throw new SignatureRequestException($bundle ? "Il modulo «{$document->name}» non ha slot di firma configurati." : 'Il modulo non ha slot di firma configurati.');
            }

            $originals[$index] = $original;

            foreach ($slots as $slot) {
                $slotRows[] = $slot + ['document' => $index];
            }
        }

        // Uno stesso firmatario può avere più punti di firma (più righe con lo stesso slot).
        $moduleSlots = array_values(array_unique(array_column($slotRows, 'slot')));
        $bySlot = [];
        foreach ($signers as $signer) {
            $bySlot[$signer->slot] = $signer;
        }

        $given = array_keys($bySlot);
        sort($given);
        $expected = $moduleSlots;
        sort($expected);

        if (count($signers) !== count($moduleSlots) || $given !== $expected) {
            throw new SignatureRequestException('I firmatari non corrispondono agli slot di firma del modulo.');
        }

        foreach ($documents as $document) {
            $this->assertNoOpenRequest($document);
        }

        $provider = $this->providers->provider();
        $envelopeSigners = $this->buildEnvelopeSigners($slotRows, $bySlot, $provider->requiredContactFields());

        try {
            $pdfs = array_map(fn (string $original): string => $this->pdfFiller->flattenContent($original), $originals);
        } catch (PdfFormException) {
            throw new SignatureRequestException('Preparazione del PDF per la firma non riuscita.');
        }

        $requests = $documents[0]->getConnection()->transaction(function () use ($documents, $slotRows, $envelopeSigners, $provider, $user): array {
            Document::query()->whereKey(array_map(fn (Document $document) => $document->getKey(), $documents))->orderBy('id')->lockForUpdate()->get();
            $expiresAt = now()->addDays((int) config('unico-core.signature.ttl_days'));
            $created = [];

            foreach ($documents as $index => $document) {
                $this->assertNoOpenRequest($document);

                $request = SignatureRequest::create([
                    'document_id' => $document->getKey(),
                    'provider' => $provider->name(),
                    'status' => SignatureRequestStatus::Pending,
                    'expires_at' => $expiresAt,
                    'requested_by' => $user?->getAuthIdentifier(),
                ]);

                $ownSlots = array_column(array_filter($slotRows, fn (array $row): bool => $row['document'] === $index), 'slot');

                foreach ($envelopeSigners as $signer) {
                    $input = $signer['input'];

                    if (! in_array($input->slot, $ownSlots, true)) {
                        continue;
                    }

                    $request->signatureRequestSigners()->create([
                        'position' => $signer['envelope']->position,
                        'slot' => $input->slot,
                        'role' => $input->role,
                        'name' => trim($input->firstName.' '.$input->lastName),
                        'first_name' => $input->firstName,
                        'last_name' => $input->lastName,
                        'tax_code' => $input->taxCode,
                        'email' => $input->email,
                        'phone' => $signer['envelope']->phone,
                        'signer_type' => $input->signerType,
                        'signer_ref' => $input->signerRef,
                        'status' => SignerStatus::Waiting,
                    ]);
                }

                $created[] = $request;
            }

            return $created;
        });

        $first = $documents[0];
        $envelope = new EnvelopeData(
            name: $bundle ? 'Plico di firma: '.Str::limit(implode(', ', array_map(fn (Document $document): string => (string) $document->name, $documents)), 120, '') : (string) $first->name,
            fileName: (Str::slug((string) $first->name) ?: 'documento').'.pdf',
            pdf: $pdfs[0],
            signers: array_map(fn (array $signer): EnvelopeSigner => $signer['envelope'], $envelopeSigners),
            expiresAt: $requests[0]->expires_at,
            externalId: (string) $requests[0]->id,
            emailSubject: $bundle ? $this->bundleEmailSubject($first) : $this->emailSubject($first),
            extraDocuments: array_map(
                fn (int $index): EnvelopeDocument => new EnvelopeDocument((string) $documents[$index]->name, (Str::slug((string) $documents[$index]->name) ?: 'documento').'.pdf', $pdfs[$index]),
                array_slice(array_keys($documents), 1),
            ),
        );

        try {
            $ref = $provider->createEnvelope($envelope);
        } catch (SignatureException $e) {
            $reason = $this->safeReason($e->getMessage());
            $this->failRequests($requests, $reason);

            throw new SignatureRequestException('Invio per la firma non riuscito: '.$reason, 0, $e);
        } catch (\Throwable $e) {
            $reason = 'Errore imprevisto del provider';
            $this->failRequests($requests, $reason);

            throw new SignatureRequestException('Invio per la firma non riuscito: '.$reason, 0, $e);
        }

        try {
            $requests[0]->getConnection()->transaction(function () use ($requests, $ref, $user): void {
                foreach ($requests as $index => $request) {
                    $request->update([
                        'provider_ref' => $ref->providerRef,
                        'provider_document_ref' => $ref->documentRefs[$index] ?? null,
                        'status' => SignatureRequestStatus::Sent,
                        'sent_at' => now(),
                    ]);

                    foreach ($request->signatureRequestSigners as $signer) {
                        $signer->update([
                            'provider_signer_ref' => $ref->signerRefs[$signer->slot] ?? null,
                            'status' => SignerStatus::Notified,
                        ]);
                    }

                    event(new SignatureSent($request, $user));
                }
            });
        } catch (\Throwable $e) {
            $reason = 'Errore nel salvataggio della richiesta di firma';
            $this->failRequests($requests, $reason, clearRef: true);

            try {
                $provider->cancelEnvelope($ref->providerRef);
            } catch (\Throwable) {
                // Annullamento best effort: la richiesta locale e' gia' marcata non riuscita.
            }

            throw new SignatureRequestException('Invio per la firma non riuscito: '.$reason, 0, $e);
        }

        return array_map(fn (SignatureRequest $request): SignatureRequest => $request->refresh(), $requests);
    }

    /** @param  array<int, SignatureRequest>  $requests */
    private function failRequests(array $requests, string $reason, bool $clearRef = false): void
    {
        foreach ($requests as $request) {
            $request->forceFill(['status' => SignatureRequestStatus::Failed, 'failure_reason' => $reason] + ($clearRef ? ['provider_ref' => null] : []))->save();
        }
    }

    private function bundleEmailSubject(Document $document): string
    {
        $owner = trim((string) ($document->documentable?->name ?? ''));

        return trim(sprintf('Firma documenti - ore %s %s', now()->format('H:i'), $owner));
    }

    /**
     * Oggetto dell'email al firmatario: tipo documento, orario di invio e cliente (distingue gli invii ravvicinati).
     */
    private function emailSubject(Document $document): string
    {
        $type = (string) ($document->documentType?->name ?? 'documento');
        $owner = trim((string) ($document->documentable?->name ?? ''));

        return trim(sprintf('Firma %s - ore %s %s', $type, now()->format('H:i'), $owner));
    }

    /**
     * Allinea la richiesta allo stato della busta presso il provider (idempotente).
     *
     * @throws SignatureRequestException
     */
    public function reconcile(SignatureRequest $request): SignatureRequest
    {
        if ($request->status !== SignatureRequestStatus::Sent || blank($request->provider_ref)) {
            return $request;
        }

        $provider = $this->providers->provider($request->provider);
        $pdf = null;

        try {
            $envelope = $provider->envelopeStatus($request->provider_ref);

            if ($envelope->status === SignatureRequestStatus::Signed) {
                $pdf = $provider->downloadSigned($request->provider_ref, $request->provider_document_ref);
            }
        } catch (\Throwable $e) {
            SignatureRequest::query()->whereKey($request->getKey())->update(['last_synced_at' => now()]);

            throw new SignatureRequestException('Impossibile aggiornare lo stato della firma, riprovare più tardi.', 0, $e);
        }

        $request->getConnection()->transaction(function () use ($request, $envelope, $pdf): void {
            $locked = SignatureRequest::query()->whereKey($request->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== SignatureRequestStatus::Sent) {
                return;
            }

            $this->applySignerStates($locked, $envelope);
            $locked->last_synced_at = now();

            match ($envelope->status) {
                SignatureRequestStatus::Signed => $this->completeRequest($locked, (string) $pdf),
                SignatureRequestStatus::Declined,
                SignatureRequestStatus::Expired,
                SignatureRequestStatus::Cancelled => $locked->forceFill(['status' => $envelope->status, 'failure_reason' => null])->save(),
                default => $locked->save(),
            };
        });

        return $request->refresh();
    }

    /**
     * Annulla una richiesta aperta.
     *
     * @throws SignatureRequestException
     */
    public function cancel(SignatureRequest $request, ?Authenticatable $user = null): SignatureRequest
    {
        if (! $request->refresh()->isOpen()) {
            throw new SignatureRequestException('La richiesta di firma non è più aperta.');
        }

        if (filled($request->provider_ref)) {
            try {
                $this->providers->provider($request->provider)->cancelEnvelope($request->provider_ref);
            } catch (\Throwable $e) {
                throw new SignatureRequestException('Annullamento non riuscito, riprovare più tardi.', 0, $e);
            }
        }

        $request->getConnection()->transaction(function () use ($request, $user): void {
            // Le richieste dello stesso plico condividono la busta: si annullano insieme.
            $locked = SignatureRequest::query()
                ->where(fn ($query) => $query->whereKey($request->getKey())->orWhere(fn ($same) => $same->where('provider', $request->provider)->whereNotNull('provider_ref')->where('provider_ref', $request->provider_ref)))
                ->lockForUpdate()
                ->get();

            foreach ($locked as $sibling) {
                if (! $sibling->isOpen()) {
                    continue;
                }

                $sibling->forceFill(['status' => SignatureRequestStatus::Cancelled])->save();

                event(new SignatureCancelled($sibling, $user));
            }
        });

        return $request->refresh();
    }

    /**
     * Chiude le richieste scadute o rimaste in preparazione; ritorna quante ne ha modificate.
     */
    public function expireOverdue(int $limit = 20): int
    {
        $changed = 0;

        $overdue = SignatureRequest::query()
            ->where('status', SignatureRequestStatus::Sent)
            ->where('expires_at', '<', now())
            ->orderByRaw('last_synced_at IS NOT NULL, last_synced_at ASC')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($overdue as $request) {
            try {
                $request = $this->reconcile($request);
            } catch (\Throwable) {
                Log::warning("Riconciliazione non riuscita per la richiesta di firma {$request->id}");

                continue;
            }

            if ($request->status !== SignatureRequestStatus::Sent) {
                $changed++;

                continue;
            }

            if (filled($request->provider_ref)) {
                try {
                    $this->providers->provider($request->provider)->cancelEnvelope($request->provider_ref);
                } catch (\Throwable) {
                    Log::warning("Annullamento busta non riuscito per la richiesta di firma {$request->id}");
                }
            }

            $changed += SignatureRequest::query()
                ->whereKey($request->getKey())
                ->where('status', SignatureRequestStatus::Sent)
                ->update(['status' => SignatureRequestStatus::Expired]);
        }

        $changed += SignatureRequest::query()
            ->where('status', SignatureRequestStatus::Pending)
            ->whereNull('provider_ref')
            ->where('created_at', '<', now()->subMinutes(15))
            ->update(['status' => SignatureRequestStatus::Failed, 'failure_reason' => 'Richiesta non completata']);

        return $changed;
    }

    /**
     * Registra l'arrivo di un evento; non cambia lo stato (lo fa reconcile).
     */
    public function applyWebhook(string $provider, SignatureEvent $event): ?SignatureRequest
    {
        $request = SignatureRequest::query()
            ->where('provider', $provider)
            ->where('provider_ref', $event->providerRef)
            ->first();

        if ($request !== null) {
            SignatureRequest::query()->where('provider', $provider)->where('provider_ref', $event->providerRef)->update(['last_event_at' => now()]);
            $request->refresh();
        }

        return $request;
    }

    private function applySignerStates(SignatureRequest $request, EnvelopeStatus $envelope): void
    {
        $states = collect($envelope->signers)->keyBy('providerSignerRef');

        foreach ($request->signatureRequestSigners as $signer) {
            $state = $states->get($signer->provider_signer_ref);

            if ($state === null) {
                continue;
            }

            $signer->forceFill(['status' => $state->status, 'signed_at' => $state->signedAt])->save();
        }
    }

    /**
     * Il PDF firmato va nella collection `signed` dello stesso documento (che resta l'originale nella collection
     * `documents`) e il documento risulta firmato. Le app reagiscono a `SignatureCompleted` (es. unicoloan approva il QAV).
     */
    private function completeRequest(SignatureRequest $request, string $pdf): void
    {
        $document = Document::query()->whereKey($request->document_id)->firstOrFail();
        $signedAt = now();

        $this->storeSigned($document, $pdf, (Str::slug((string) $document->name) ?: 'documento').'-firmato.pdf');

        $document->forceFill(['is_signed' => true, 'signed_at' => $signedAt] + $this->signedBy($request, $document));
        // Il firmato sostituisce l'originale anche sul cloud: un documento già sincronizzato torna da caricare.
        if ($document->sync_status === SyncStatus::SYNCED->value) {
            $document->sync_status = SyncStatus::LOCAL->value;
        }
        $document->save();
        $request->forceFill(['status' => SignatureRequestStatus::Signed, 'signed_at' => $signedAt, 'failure_reason' => null])->save();

        event(new SignatureCompleted($request, $document));
    }

    /**
     * Chi ha firmato, come alias MorphMap: il firmatario della richiesta (client, agent → fornitore, user), preferendo quello
     * previsto dal tipo documento (`signer_model`). Vuoto se i firmatari sono inseriti a mano.
     *
     * @return array{signed_by_type?: string, signed_by_id?: string}
     */
    private function signedBy(SignatureRequest $request, Document $document): array
    {
        $aliases = ['client' => 'client', 'agent' => 'fornitore', 'user' => 'user'];
        $expected = $document->documentType?->signer_model;

        $signer = $request->signatureRequestSigners
            ->filter(fn ($signer): bool => isset($aliases[$signer->signer_type]) && filled($signer->signer_ref))
            ->sortBy(fn ($signer): int => ($aliases[$signer->signer_type] ?? null) === $expected ? 0 : 1)
            ->first();

        return $signer === null ? [] : ['signed_by_type' => $aliases[$signer->signer_type], 'signed_by_id' => (string) $signer->signer_ref];
    }

    /** Il PDF originale del documento (collection `documents`), o null se manca o non è un PDF. */
    protected function readOriginalPdf(Document $document): ?string
    {
        $media = $document->getFirstMedia(Document::COLLECTION);

        if ($media === null || $media->mime_type !== 'application/pdf') {
            return null;
        }

        return (string) stream_get_contents($media->stream());
    }

    /** Salva il PDF firmato nella collection `signed` del documento. */
    protected function storeSigned(Document $document, string $pdf, string $fileName): void
    {
        $document->addMediaFromString($pdf)->usingFileName($fileName)->toMediaCollection(Document::COLLECTION_SIGNED);
    }

    private function assertNoOpenRequest(Document $document): void
    {
        if ($document->signatureRequests()->open()->exists()) {
            throw new SignatureRequestException('Esiste già una richiesta di firma aperta per questo documento.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     * @param  array<string, SignerInput>  $bySlot
     * @param  array<int, string>  $requiredFields
     * @return array<int, array{input: SignerInput, envelope: EnvelopeSigner}>
     */
    private function buildEnvelopeSigners(array $slots, array $bySlot, array $requiredFields): array
    {
        $result = [];

        $grouped = [];

        foreach ($slots as $slot) {
            $grouped[$slot['slot']][] = $slot;
        }

        foreach (array_values($grouped) as $index => $placements) {
            $slot = $placements[0];
            $input = $bySlot[$slot['slot']];
            $label = trim($input->firstName.' '.$input->lastName) ?: $input->slot;

            if (trim($input->firstName) === '' || trim($input->lastName) === '') {
                throw new SignatureRequestException("Indicare nome e cognome del firmatario «{$input->slot}».");
            }

            if (in_array('email', $requiredFields, true) && blank($input->email)) {
                throw new SignatureRequestException("Email mancante per {$label}.");
            }

            $phone = null;
            if (in_array('phone', $requiredFields, true) && blank($input->phone)) {
                throw new SignatureRequestException("Telefono mancante per {$label}.");
            }
            if (filled($input->phone)) {
                $phone = PhoneNumber::toE164($input->phone);

                if ($phone === null) {
                    throw new SignatureRequestException("Telefono non valido per {$label}.");
                }
            }

            $result[] = [
                'input' => $input,
                'envelope' => new EnvelopeSigner(
                    $index + 1,
                    $input->slot,
                    $input->role,
                    $input->firstName,
                    $input->lastName,
                    filled($input->email) ? $input->email : null,
                    $phone,
                    $input->taxCode,
                    new SignaturePlacement((int) $slot['page'], (float) $slot['x'], (float) $slot['y'], (float) $slot['width'], (float) $slot['height'], (int) ($slot['document'] ?? 0)),
                    array_map(fn (array $extra): SignaturePlacement => new SignaturePlacement((int) $extra['page'], (float) $extra['x'], (float) $extra['y'], (float) $extra['width'], (float) $extra['height'], (int) ($extra['document'] ?? 0)), array_slice($placements, 1)),
                ),
            ];
        }

        return $result;
    }

    /**
     * Motivo breve senza dati personali (email e sequenze numeriche lunghe oscurate).
     */
    private function safeReason(string $message): string
    {
        $message = preg_replace('/[^\s@]+@[^\s@]+/', '[email]', $message);
        $message = preg_replace('/\+?\d[\d\s\-().]{5,}\d/', '[numero]', (string) $message);

        return Str::limit(trim((string) $message), 200, '');
    }
}
