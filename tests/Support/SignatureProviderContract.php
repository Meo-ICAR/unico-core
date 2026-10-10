<?php

namespace Unico\Core\Tests\Support;

use Unico\Core\Enums\SignatureRequestStatus;
use Unico\Core\Enums\SignerRole;
use Unico\Core\Enums\SignerStatus;
use Unico\Core\Signature\Dto\EnvelopeData;
use Unico\Core\Signature\Dto\EnvelopeSigner;
use Unico\Core\Signature\Dto\SignaturePlacement;
use Unico\Core\Signature\Contracts\SignatureProvider;

/**
 * Test riusabili: ogni adapter di provider deve superarli.
 */
trait SignatureProviderContract
{
    abstract protected function provider(): SignatureProvider;

    abstract protected function completeAll(string $ref): void;

    protected function makeEnvelope(): EnvelopeData
    {
        $placement = new SignaturePlacement(1, 50.0, 700.0, 150.0, 40.0);

        return new EnvelopeData(
            name: 'Modulo di prova',
            fileName: 'modulo-prova.pdf',
            pdf: (string) file_get_contents(__DIR__.'/../Fixtures/pdf/modulo-prova.pdf'),
            signers: [
                new EnvelopeSigner(1, 'signer1', SignerRole::Client, 'Mario', 'Rossi', 'mario@example.test', '+393381234567', null, $placement),
                new EnvelopeSigner(2, 'signer2', SignerRole::Collaborator, 'Anna', 'Verdi', 'anna@example.test', '+393397654321', null, $placement),
            ],
            expiresAt: now()->addDays(7),
            externalId: 'test-document-1',
        );
    }

    public function test_create_envelope_returns_ref_and_a_signer_ref_per_slot(): void
    {
        $ref = $this->provider()->createEnvelope($this->makeEnvelope());

        $this->assertNotSame('', $ref->providerRef);
        $this->assertEqualsCanonicalizing(['signer1', 'signer2'], array_keys($ref->signerRefs));
        $this->assertCount(2, array_unique($ref->signerRefs));
    }

    public function test_new_envelope_is_sent_with_waiting_signers(): void
    {
        $ref = $this->provider()->createEnvelope($this->makeEnvelope());

        $status = $this->provider()->envelopeStatus($ref->providerRef);

        $this->assertSame(SignatureRequestStatus::Sent, $status->status);
        $this->assertCount(2, $status->signers);
        foreach ($status->signers as $signer) {
            $this->assertContains($signer->providerSignerRef, $ref->signerRefs);
            $this->assertContains($signer->status, [SignerStatus::Waiting, SignerStatus::Notified]);
            $this->assertNull($signer->signedAt);
        }
    }

    public function test_completed_envelope_is_signed_and_downloadable_as_pdf(): void
    {
        $ref = $this->provider()->createEnvelope($this->makeEnvelope());
        $this->completeAll($ref->providerRef);

        $status = $this->provider()->envelopeStatus($ref->providerRef);

        $this->assertSame(SignatureRequestStatus::Signed, $status->status);
        foreach ($status->signers as $signer) {
            $this->assertSame(SignerStatus::Signed, $signer->status);
            $this->assertNotNull($signer->signedAt);
        }
        $this->assertStringStartsWith('%PDF', $this->provider()->downloadSigned($ref->providerRef));
    }

    public function test_cancelled_envelope_reports_cancelled(): void
    {
        $ref = $this->provider()->createEnvelope($this->makeEnvelope());

        $this->provider()->cancelEnvelope($ref->providerRef);

        $this->assertSame(
            SignatureRequestStatus::Cancelled,
            $this->provider()->envelopeStatus($ref->providerRef)->status,
        );
    }
}
