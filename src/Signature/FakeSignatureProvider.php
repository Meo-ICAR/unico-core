<?php

namespace Unico\Core\Signature;

use Unico\Core\Signature\Contracts\SignatureProvider;
use Unico\Core\Enums\SignatureRequestStatus;
use Unico\Core\Enums\SignerStatus;
use Unico\Core\Signature\Dto\EnvelopeData;
use Unico\Core\Signature\Dto\EnvelopeRef;
use Unico\Core\Signature\Dto\EnvelopeStatus;
use Unico\Core\Signature\Dto\SignatureEvent;
use Unico\Core\Signature\Dto\SignerState;
use Unico\Core\Signature\Exceptions\EnvelopeNotFoundException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Provider finto per test e sviluppo: lo stato vive nella cache e avanza solo su richiesta esplicita.
 */
class FakeSignatureProvider implements SignatureProvider
{
    private const TTL_DAYS = 30;

    public function name(): string
    {
        return 'fake';
    }

    public function requiredContactFields(): array
    {
        return ['email', 'phone'];
    }

    public function createEnvelope(EnvelopeData $envelope): EnvelopeRef
    {
        $ref = 'fake-'.Str::uuid();
        $signers = [];
        $signerRefs = [];

        foreach ($envelope->signers as $signer) {
            $signerRef = 'fake-signer-'.Str::uuid();
            $signerRefs[$signer->slot] = $signerRef;
            $signers[$signer->slot] = ['ref' => $signerRef, 'status' => SignerStatus::Notified->value, 'signed_at' => null];
        }

        $documents = array_map(fn ($document): string => base64_encode($document->pdf), $envelope->documents());

        $this->store($ref, [
            'status' => SignatureRequestStatus::Sent->value,
            'pdf' => $documents[0],
            'documents' => $documents,
            'signers' => $signers,
        ]);

        return new EnvelopeRef($ref, $signerRefs, array_map(fn (int $index): string => "fake-doc-{$index}", array_keys($documents)));
    }

    public function envelopeStatus(string $providerRef): EnvelopeStatus
    {
        $state = $this->load($providerRef);

        return new EnvelopeStatus(
            SignatureRequestStatus::from($state['status']),
            array_values(array_map(
                fn (array $signer): SignerState => new SignerState(
                    $signer['ref'],
                    SignerStatus::from($signer['status']),
                    $signer['signed_at'] ? CarbonImmutable::parse($signer['signed_at']) : null,
                ),
                $state['signers'],
            )),
        );
    }

    public function downloadSigned(string $providerRef, ?string $documentRef = null): string
    {
        $state = $this->load($providerRef);
        $index = $documentRef !== null && str_starts_with($documentRef, 'fake-doc-') ? (int) substr($documentRef, 9) : 0;

        return base64_decode($state['documents'][$index] ?? $state['pdf'])."\n%FAKE-SIGNED\n";
    }

    public function cancelEnvelope(string $providerRef): void
    {
        $this->setEnvelopeStatus($providerRef, SignatureRequestStatus::Cancelled);
    }

    public function parseWebhook(Request $request): ?SignatureEvent
    {
        if ($request->header('X-Fake-Signature') !== 'ok') {
            return null;
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ! isset($payload['ref'], $payload['type'], $payload['id'])) {
            return new SignatureEvent((string) ($payload['ref'] ?? ''), 'ignored', (string) ($payload['id'] ?? ''));
        }

        return new SignatureEvent((string) $payload['ref'], (string) $payload['type'], (string) $payload['id']);
    }

    public function completeSigner(string $providerRef, string $slot): void
    {
        $state = $this->load($providerRef);
        $state['signers'][$slot]['status'] = SignerStatus::Signed->value;
        $state['signers'][$slot]['signed_at'] = now()->toIso8601String();

        $allSigned = collect($state['signers'])->every(fn (array $signer): bool => $signer['status'] === SignerStatus::Signed->value);
        if ($allSigned) {
            $state['status'] = SignatureRequestStatus::Signed->value;
        }

        $this->store($providerRef, $state);
    }

    public function completeAll(string $providerRef): void
    {
        foreach (array_keys($this->load($providerRef)['signers']) as $slot) {
            $this->completeSigner($providerRef, $slot);
        }
    }

    public function decline(string $providerRef): void
    {
        $this->setEnvelopeStatus($providerRef, SignatureRequestStatus::Declined);
    }

    public function expire(string $providerRef): void
    {
        $this->setEnvelopeStatus($providerRef, SignatureRequestStatus::Expired);
    }

    private function setEnvelopeStatus(string $providerRef, SignatureRequestStatus $status): void
    {
        $state = $this->load($providerRef);
        $state['status'] = $status->value;
        $this->store($providerRef, $state);
    }

    /**
     * @return array{status: string, pdf: string, signers: array<string, array{ref: string, status: string, signed_at: ?string}>}
     */
    private function load(string $providerRef): array
    {
        return Cache::get($this->key($providerRef))
            ?? throw new EnvelopeNotFoundException("Busta di firma [{$providerRef}] non trovata.");
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function store(string $providerRef, array $state): void
    {
        Cache::put($this->key($providerRef), $state, now()->addDays(self::TTL_DAYS));
    }

    private function key(string $providerRef): string
    {
        return "signature.fake.{$providerRef}";
    }
}
