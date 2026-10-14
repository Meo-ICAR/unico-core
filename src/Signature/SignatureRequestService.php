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
        $original = $this->readOriginalPdf($document);

        if ($original === null) {
            throw new SignatureRequestException('Il documento non ha un PDF da firmare.');
        }

        $slots = $document->documentType?->pdfModule?->signature_slots ?? [];

        if ($slots === []) {
            throw new SignatureRequestException('Il modulo non ha slot di firma configurati.');
        }

        $moduleSlots = array_column($slots, 'slot');
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

        $this->assertNoOpenRequest($document);

        $provider = $this->providers->provider();
        $envelopeSigners = $this->buildEnvelopeSigners($slots, $bySlot, $provider->requiredContactFields());

        try {
            $pdf = $this->pdfFiller->flattenContent($original);
        } catch (PdfFormException) {
            throw new SignatureRequestException('Preparazione del PDF per la firma non riuscita.');
        }

        $request = $document->getConnection()->transaction(function () use ($document, $envelopeSigners, $provider, $user): SignatureRequest {
            Document::query()->whereKey($document->getKey())->lockForUpdate()->first();
            $this->assertNoOpenRequest($document);

            $request = SignatureRequest::create([
                'document_id' => $document->getKey(),
                'provider' => $provider->name(),
                'status' => SignatureRequestStatus::Pending,
                'expires_at' => now()->addDays((int) config('unico-core.signature.ttl_days')),
                'requested_by' => $user?->getAuthIdentifier(),
            ]);

            foreach ($envelopeSigners as $signer) {
                $input = $signer['input'];
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

            return $request;
        });

        $envelope = new EnvelopeData(
            name: (string) $document->name,
            fileName: (Str::slug((string) $document->name) ?: 'documento').'.pdf',
            pdf: $pdf,
            signers: array_map(fn (array $signer): EnvelopeSigner => $signer['envelope'], $envelopeSigners),
            expiresAt: $request->expires_at,
            externalId: (string) $request->id,
            emailSubject: $this->emailSubject($document),
        );

        try {
            $ref = $provider->createEnvelope($envelope);
        } catch (SignatureException $e) {
            $reason = $this->safeReason($e->getMessage());
            $request->update(['status' => SignatureRequestStatus::Failed, 'failure_reason' => $reason]);

            throw new SignatureRequestException('Invio per la firma non riuscito: '.$reason, 0, $e);
        } catch (\Throwable $e) {
            $reason = 'Errore imprevisto del provider';
            $request->update(['status' => SignatureRequestStatus::Failed, 'failure_reason' => $reason]);

            throw new SignatureRequestException('Invio per la firma non riuscito: '.$reason, 0, $e);
        }

        try {
            $request->getConnection()->transaction(function () use ($request, $ref, $user): void {
                $request->update([
                    'provider_ref' => $ref->providerRef,
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
            });
        } catch (\Throwable $e) {
            $reason = 'Errore nel salvataggio della richiesta di firma';
            $request->forceFill(['status' => SignatureRequestStatus::Failed, 'failure_reason' => $reason, 'provider_ref' => null])->save();

            try {
                $provider->cancelEnvelope($ref->providerRef);
            } catch (\Throwable) {
                // Annullamento best effort: la richiesta locale e' gia' marcata non riuscita.
            }

            throw new SignatureRequestException('Invio per la firma non riuscito: '.$reason, 0, $e);
        }

        return $request->refresh();
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
                $pdf = $provider->downloadSigned($request->provider_ref);
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
            $locked = SignatureRequest::query()->whereKey($request->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->isOpen()) {
                return;
            }

            $locked->forceFill(['status' => SignatureRequestStatus::Cancelled])->save();

            event(new SignatureCancelled($locked, $user));
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

        $request?->forceFill(['last_event_at' => now()])->save();

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

        $document->forceFill(['is_signed' => true, 'signed_at' => $signedAt]);
        // Il firmato sostituisce l'originale anche sul cloud: un documento già sincronizzato torna da caricare.
        if ($document->sync_status === SyncStatus::SYNCED->value) {
            $document->sync_status = SyncStatus::LOCAL->value;
        }
        $document->save();
        $request->forceFill(['status' => SignatureRequestStatus::Signed, 'signed_at' => $signedAt, 'failure_reason' => null])->save();

        event(new SignatureCompleted($request, $document));
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

        foreach (array_values($slots) as $index => $slot) {
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
                    new SignaturePlacement((int) $slot['page'], (float) $slot['x'], (float) $slot['y'], (float) $slot['width'], (float) $slot['height']),
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
