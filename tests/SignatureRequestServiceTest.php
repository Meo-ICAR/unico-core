<?php

namespace Unico\Core\Tests;

use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Unico\Core\Enums\SignatureRequestStatus;
use Unico\Core\Enums\SignerStatus;
use Unico\Core\Events\SignatureCancelled;
use Unico\Core\Events\SignatureCompleted;
use Unico\Core\Events\SignatureSent;
use Unico\Core\Models\Document;
use Unico\Core\Models\SignatureRequest;
use Unico\Core\Signature\Dto\EnvelopeData;
use Unico\Core\Signature\Dto\EnvelopeRef;
use Unico\Core\Signature\Dto\SignatureEvent;
use Unico\Core\Signature\Exceptions\ProviderUnavailableException;
use Unico\Core\Signature\Exceptions\SignatureRequestException;
use Unico\Core\Signature\FakeSignatureProvider;
use Unico\Core\Tests\Support\CoreDatabase;
use Unico\Core\Tests\Support\SignatureTestKit;

class SignatureRequestServiceTest extends TestCase
{
    use SignatureTestKit;

    /** @var array<int, object> */
    private array $events = [];

    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        $this->bootSignature();
        CoreDatabase::truncate('signature_request_signers', 'signature_requests', 'pdf_modules', 'documents', 'document_types');
        $this->events = [];
        foreach ([SignatureSent::class, SignatureCancelled::class, SignatureCompleted::class] as $class) {
            Container::getInstance()->make('events')->listen($class, fn ($event) => $this->events[] = $event);
        }
    }

    private function sentRequest(?object &$service = null, ?FakeSignatureProvider $provider = null): SignatureRequest
    {
        $document = $this->documentWithModule();
        $service = $this->service($provider);
        $service->originals[$document->id] = file_get_contents(__DIR__.'/Fixtures/pdf/modulo-prova.pdf');

        return $service->send($document, $this->signers());
    }

    public function test_send_creates_the_request_and_notifies_the_signers(): void
    {
        $request = $this->sentRequest();

        $this->assertSame(SignatureRequestStatus::Sent, $request->status);
        $this->assertSame('fake', $request->provider);
        $this->assertNotEmpty($request->provider_ref);
        $this->assertNotNull($request->sent_at);
        $this->assertTrue($request->expires_at->isAfter(now()->addDays(6)));

        $signers = $request->signatureRequestSigners;
        $this->assertSame(['cliente', 'collaboratore'], $signers->pluck('slot')->all());
        $this->assertSame([1, 2], $signers->pluck('position')->all());
        $this->assertSame(SignerStatus::Notified, $signers[0]->status);
        $this->assertNotEmpty($signers[0]->provider_signer_ref);
        $this->assertSame('+393331234567', $signers[0]->phone);
        $this->assertSame('+393407654321', $signers[1]->phone);
        $this->assertInstanceOf(SignatureSent::class, $this->events[0]);
    }

    public function test_send_needs_a_pdf(): void
    {
        $document = $this->documentWithModule();

        $this->expectException(SignatureRequestException::class);
        $this->expectExceptionMessage('non ha un PDF');

        $this->service()->send($document, $this->signers());
    }

    public function test_send_needs_the_module_signature_slots(): void
    {
        $document = $this->documentWithModule([]);
        $service = $this->service();
        $service->originals[$document->id] = file_get_contents(__DIR__.'/Fixtures/pdf/modulo-prova.pdf');

        $this->expectException(SignatureRequestException::class);
        $this->expectExceptionMessage('slot di firma');

        $service->send($document, $this->signers());
    }

    public function test_send_rejects_signers_that_do_not_match_the_slots(): void
    {
        $document = $this->documentWithModule();
        $service = $this->service();
        $service->originals[$document->id] = file_get_contents(__DIR__.'/Fixtures/pdf/modulo-prova.pdf');

        $this->expectException(SignatureRequestException::class);
        $this->expectExceptionMessage('non corrispondono');

        $service->send($document, [$this->signers()[0]]);
    }

    public function test_send_requires_a_valid_phone_for_every_signer(): void
    {
        $document = $this->documentWithModule();
        $service = $this->service();
        $service->originals[$document->id] = file_get_contents(__DIR__.'/Fixtures/pdf/modulo-prova.pdf');
        $signers = $this->signers();
        $signers[1] = new \Unico\Core\Signature\SignerInput('collaboratore', \Unico\Core\Enums\SignerRole::Collaborator, 'Anna', 'Bianchi', 'anna@example.com', 'non-un-numero', null, 'user', '2');

        $this->expectException(SignatureRequestException::class);
        $this->expectExceptionMessage('Telefono non valido per Anna Bianchi');

        $service->send($document, $signers);
    }

    public function test_only_one_open_request_per_document(): void
    {
        $request = $this->sentRequest($service);

        $this->expectException(SignatureRequestException::class);
        $this->expectExceptionMessage('già una richiesta di firma aperta');

        $service->send(Document::find($request->document_id), $this->signers());
    }

    public function test_provider_failure_marks_the_request_failed_without_personal_data(): void
    {
        $document = $this->documentWithModule();
        $failing = new class extends FakeSignatureProvider
        {
            public function createEnvelope(EnvelopeData $envelope): EnvelopeRef
            {
                throw new ProviderUnavailableException('Rifiutato per mario@example.com, tel 3331234567');
            }
        };
        $service = $this->service($failing);
        $service->originals[$document->id] = file_get_contents(__DIR__.'/Fixtures/pdf/modulo-prova.pdf');

        try {
            $service->send($document, $this->signers());
            $this->fail('atteso SignatureRequestException');
        } catch (SignatureRequestException $e) {
            $this->assertStringNotContainsString('mario@example.com', $e->getMessage());
        }

        $request = SignatureRequest::first();
        $this->assertSame(SignatureRequestStatus::Failed, $request->status);
        $this->assertStringContainsString('[email]', $request->failure_reason);
        $this->assertStringNotContainsString('3331234567', $request->failure_reason);
    }

    public function test_reconcile_stores_the_signed_pdf_and_flags_the_document(): void
    {
        $provider = new FakeSignatureProvider;
        $request = $this->sentRequest($service, $provider);
        $provider->completeAll($request->provider_ref);

        $request = $service->reconcile($request);

        $this->assertSame(SignatureRequestStatus::Signed, $request->status);
        $this->assertNotNull($request->signed_at);
        $this->assertSame([SignerStatus::Signed, SignerStatus::Signed], $request->signatureRequestSigners->pluck('status')->all());

        $document = Document::find($request->document_id);
        $this->assertTrue($document->is_signed);
        $this->assertNotNull($document->signed_at);
        $this->assertSame('qav-rossi-firmato.pdf', $service->signed[$document->id]['name']);
        $this->assertStringContainsString('%FAKE-SIGNED', $service->signed[$document->id]['pdf']);

        $completed = array_values(array_filter($this->events, fn ($e) => $e instanceof SignatureCompleted));
        $this->assertCount(1, $completed);
        $this->assertSame($document->id, $completed[0]->document->id);
    }

    public function test_reconcile_is_idempotent(): void
    {
        $provider = new FakeSignatureProvider;
        $request = $this->sentRequest($service, $provider);
        $provider->completeAll($request->provider_ref);

        $service->reconcile($request);
        $service->reconcile($request->refresh());

        $this->assertCount(1, array_filter($this->events, fn ($e) => $e instanceof SignatureCompleted));
    }

    public function test_reconcile_records_a_decline_without_a_signed_file(): void
    {
        $provider = new FakeSignatureProvider;
        $request = $this->sentRequest($service, $provider);
        $provider->decline($request->provider_ref);

        $request = $service->reconcile($request);

        $this->assertSame(SignatureRequestStatus::Declined, $request->status);
        $this->assertSame([], $service->signed);
        $this->assertFalse((bool) Document::find($request->document_id)->is_signed);
    }

    public function test_reconcile_keeps_a_partially_signed_request_open(): void
    {
        $provider = new FakeSignatureProvider;
        $request = $this->sentRequest($service, $provider);
        $provider->completeSigner($request->provider_ref, 'cliente');

        $request = $service->reconcile($request);

        $this->assertSame(SignatureRequestStatus::Sent, $request->status);
        $this->assertSame([SignerStatus::Signed, SignerStatus::Notified], $request->signatureRequestSigners->pluck('status')->all());
    }

    public function test_provider_errors_on_reconcile_are_reported_and_leave_the_state_alone(): void
    {
        $request = $this->sentRequest($service);
        $request->forceFill(['provider_ref' => 'fake-inesistente'])->save();

        try {
            $service->reconcile($request);
            $this->fail('atteso SignatureRequestException');
        } catch (SignatureRequestException) {
            $this->assertSame(SignatureRequestStatus::Sent, $request->refresh()->status);
            $this->assertNotNull($request->last_synced_at);
        }
    }

    public function test_cancel_closes_the_request_and_the_envelope(): void
    {
        $provider = new FakeSignatureProvider;
        $request = $this->sentRequest($service, $provider);

        $request = $service->cancel($request);

        $this->assertSame(SignatureRequestStatus::Cancelled, $request->status);
        $this->assertSame(SignatureRequestStatus::Cancelled, $provider->envelopeStatus($request->provider_ref)->status);
        $this->assertCount(1, array_filter($this->events, fn ($e) => $e instanceof SignatureCancelled));

        $this->expectException(SignatureRequestException::class);
        $service->cancel($request);
    }

    public function test_expire_overdue_closes_late_requests_and_abandoned_drafts(): void
    {
        $late = $this->sentRequest($service);
        $late->forceFill(['expires_at' => now()->subDay()])->save();

        $abandoned = SignatureRequest::create([
            'document_id' => $late->document_id, 'provider' => 'fake', 'status' => SignatureRequestStatus::Pending,
            'expires_at' => now()->addDays(7),
        ]);
        $abandoned->forceFill(['created_at' => Carbon::now()->subHour()])->save();

        $changed = $service->expireOverdue();

        $this->assertSame(2, $changed);
        $this->assertSame(SignatureRequestStatus::Expired, $late->refresh()->status);
        $this->assertSame(SignatureRequestStatus::Failed, $abandoned->refresh()->status);
    }

    public function test_apply_webhook_only_records_the_event_time(): void
    {
        $request = $this->sentRequest($service);

        $found = $service->applyWebhook('fake', new SignatureEvent($request->provider_ref, 'signer.done', 'evt1'));

        $this->assertSame($request->id, $found->id);
        $this->assertNotNull($found->last_event_at);
        $this->assertSame(SignatureRequestStatus::Sent, $found->refresh()->status);
        $this->assertNull($service->applyWebhook('fake', new SignatureEvent('altro', 'signer.done', 'evt2')));
    }
}
