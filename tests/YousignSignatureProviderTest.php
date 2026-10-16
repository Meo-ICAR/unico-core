<?php

namespace Unico\Core\Tests;

use Unico\Core\Enums\SignatureRequestStatus;
use Unico\Core\Enums\SignerStatus;
use Unico\Core\Signature\Dto\EnvelopeData;
use Unico\Core\Signature\Exceptions\EnvelopeNotFoundException;
use Unico\Core\Signature\Exceptions\InvalidEnvelopeException;
use Unico\Core\Signature\Exceptions\ProviderUnavailableException;
use Unico\Core\Signature\Contracts\SignatureProvider;
use Unico\Core\Signature\SignatureProviderManager;
use Unico\Core\Signature\YousignSignatureProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Unico\Core\Tests\Support\SignatureProviderContract;
use Unico\Core\Tests\Support\SignatureTestKit;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class YousignSignatureProviderTest extends TestCase
{
    use SignatureProviderContract, SignatureTestKit;

    private const KEY = 'test-key-123';

    private const SECRET = 'test-webhook-secret';

    private const BASE = 'https://yousign.test/v3';

    /** @var array{status: string, signerStatus: string, createdSigners: int} */
    private array $state = ['status' => 'ongoing', 'signerStatus' => 'notified', 'createdSigners' => 0];

    protected function setUp(): void
    {
        $this->bootSignature();

        config(['unico-core.signature.yousign' => [
            'api_key' => self::KEY,
            'base_url' => self::BASE,
            'webhook_secret' => self::SECRET,
            'signature_level' => 'electronic_signature',
            'timeout' => 5,
            'retry_delay_ms' => 0,
        ]]);
        Http::preventStrayRequests();
    }

    private bool $faked = false;

    /**
     * @param  \Closure|array<string, mixed>  $fake
     */
    private function useFake(\Closure|array $fake): void
    {
        $this->faked = true;
        Http::fake($fake);
    }

    protected function provider(): SignatureProvider
    {
        if (! $this->faked) {
            $this->fakeHappyPath();
        }

        return new YousignSignatureProvider(config('unico-core.signature.yousign'));
    }

    protected function completeAll(string $ref): void
    {
        $this->state['status'] = 'done';
        $this->state['signerStatus'] = 'signed';
    }

    private function fakeHappyPath(): void
    {
        $pdf = (string) file_get_contents(__DIR__.'/Fixtures/pdf/modulo-prova.pdf');

        $this->useFake(function (HttpRequest $request) use ($pdf) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $path = substr($path, strlen('/v3'));
            $method = $request->method();

            return match (true) {
                $method === 'POST' && $path === '/signature_requests' => Http::response(['id' => 'sr-1'], 201),
                $method === 'POST' && $path === '/signature_requests/sr-1/documents' => Http::response(['id' => 'doc-1'], 201),
                $method === 'POST' && $path === '/signature_requests/sr-1/signers' => Http::response(['id' => 'sg-'.(++$this->state['createdSigners'])], 201),
                $method === 'POST' && $path === '/signature_requests/sr-1/activate' => Http::response(['id' => 'sr-1', 'status' => 'ongoing']),
                $method === 'POST' && $path === '/signature_requests/sr-1/cancel' => tap(Http::response([]), fn () => $this->state['status'] = 'canceled'),
                $method === 'GET' && $path === '/signature_requests/sr-1' => Http::response(['id' => 'sr-1', 'status' => $this->state['status']]),
                $method === 'GET' && $path === '/signature_requests/sr-1/signers' => Http::response([
                    ['id' => 'sg-1', 'status' => $this->state['signerStatus'], 'signed_at' => $this->state['signerStatus'] === 'signed' ? '2026-10-09T10:00:00+00:00' : null],
                    ['id' => 'sg-2', 'status' => $this->state['signerStatus'], 'signed_at' => $this->state['signerStatus'] === 'signed' ? '2026-10-09T10:05:00+00:00' : null],
                ]),
                $method === 'GET' && $path === '/signature_requests/sr-1/documents/download' => Http::response($pdf.'%signed', 200, ['Content-Type' => 'application/pdf']),
                default => Http::response(['detail' => 'non previsto '.$method.' '.$path], 404),
            };
        });
    }

    public function test_create_envelope_sequence_and_bodies(): void
    {
        $this->fakeHappyPath();

        $ref = $this->provider()->createEnvelope($this->makeEnvelope());

        $this->assertSame('sr-1', $ref->providerRef);
        $this->assertSame(['signer1' => 'sg-1', 'signer2' => 'sg-2'], $ref->signerRefs);

        Http::assertSentInOrder([
            fn (HttpRequest $r) => $r->method() === 'POST' && $r->url() === self::BASE.'/signature_requests',
            fn (HttpRequest $r) => str_ends_with($r->url(), '/signature_requests/sr-1/documents'),
            fn (HttpRequest $r) => str_ends_with($r->url(), '/signature_requests/sr-1/signers'),
            fn (HttpRequest $r) => str_ends_with($r->url(), '/signature_requests/sr-1/signers'),
            fn (HttpRequest $r) => str_ends_with($r->url(), '/signature_requests/sr-1/activate'),
        ]);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::BASE.'/signature_requests'
            && $r->hasHeader('Authorization', 'Bearer '.self::KEY)
            && $r['ordered_signers'] === true
            && $r['delivery_mode'] === 'email'
            && $r['external_id'] === 'test-document-1'
            && $r['name'] === 'Modulo di prova'
            && $r['expiration_date'] === now()->addDays(7)->format('Y-m-d'));

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/documents')
            && $r->isMultipart()
            && collect($r->data())->contains(fn ($p) => $p['name'] === 'nature' && $p['contents'] === 'signable_document')
            && collect($r->data())->contains(fn ($p) => $p['name'] === 'file' && $p['filename'] === 'modulo-prova.pdf'));

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/signers')
            && $r['info']['email'] === 'mario@example.test'
            && $r['info']['phone_number'] === '+393381234567'
            && $r['info']['locale'] === 'it'
            && $r['signature_authentication_mode'] === 'otp_sms'
            && $r['signature_level'] === 'electronic_signature'
            && $r['fields'][0]['type'] === 'signature'
            && $r['fields'][0]['document_id'] === 'doc-1'
            && $r['fields'][0]['page'] === 1
            && $r['fields'][0]['x'] == 50
            && $r['fields'][0]['y'] == 700
            && $r['fields'][0]['width'] == 150
            && $r['fields'][0]['height'] == 40);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/signers') && $r['info']['email'] === 'anna@example.test');
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'DELETE');
    }

    public function test_a_bundle_uploads_every_document_and_places_the_signatures_on_the_right_one(): void
    {
        $docs = 0;
        $this->useFake(function (HttpRequest $request) use (&$docs) {
            $path = substr(parse_url($request->url(), PHP_URL_PATH), strlen('/v3'));

            return match (true) {
                $request->method() === 'POST' && $path === '/signature_requests' => Http::response(['id' => 'sr-1'], 201),
                $request->method() === 'POST' && $path === '/signature_requests/sr-1/documents' => Http::response(['id' => 'doc-'.(++$docs)], 201),
                $request->method() === 'POST' && $path === '/signature_requests/sr-1/signers' => Http::response(['id' => 'sg-1'], 201),
                $request->method() === 'POST' && $path === '/signature_requests/sr-1/activate' => Http::response(['id' => 'sr-1'], 200),
                default => Http::response([], 404),
            };
        });
        $pdf = (string) file_get_contents(__DIR__.'/Fixtures/pdf/modulo-prova.pdf');
        $envelope = new \Unico\Core\Signature\Dto\EnvelopeData(
            'Plico', 'uno.pdf', $pdf,
            [new \Unico\Core\Signature\Dto\EnvelopeSigner(
                1, 'cliente', \Unico\Core\Enums\SignerRole::Client, 'Mario', 'Rossi', 'mario@example.test', '+393381234567', null,
                new \Unico\Core\Signature\Dto\SignaturePlacement(1, 50, 700, 150, 40),
                [new \Unico\Core\Signature\Dto\SignaturePlacement(2, 60, 600, 150, 40, 1)],
            )],
            now()->addDays(7), 'bundle-1',
            extraDocuments: [new \Unico\Core\Signature\Dto\EnvelopeDocument('Due', 'due.pdf', $pdf)],
        );

        $ref = $this->provider()->createEnvelope($envelope);

        $this->assertSame(['doc-1', 'doc-2'], $ref->documentRefs);
        Http::assertSentCount(5);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/signers')
            && $r['fields'][0]['document_id'] === 'doc-1' && $r['fields'][0]['page'] === 1
            && $r['fields'][1]['document_id'] === 'doc-2' && $r['fields'][1]['page'] === 2);
    }

    public function test_the_signed_pdf_of_one_document_of_the_bundle_is_downloaded_by_its_id(): void
    {
        $pdf = (string) file_get_contents(__DIR__.'/Fixtures/pdf/modulo-prova.pdf');
        $this->useFake(fn (HttpRequest $r) => str_ends_with($r->url(), '/signature_requests/sr-1/documents/doc-2/download')
            ? Http::response($pdf, 200, ['Content-Type' => 'application/pdf'])
            : Http::response([], 404));

        $this->assertSame($pdf, $this->provider()->downloadSigned('sr-1', 'doc-2'));
    }

    public function test_email_subject_is_sent_as_custom_text_only_when_given(): void
    {
        $this->fakeHappyPath();
        $envelope = $this->makeEnvelope();
        $withSubject = new EnvelopeData(
            $envelope->name, $envelope->fileName, $envelope->pdf, $envelope->signers, $envelope->expiresAt, $envelope->externalId, 'Firma QAV - ore 19:58 ROSSI',
        );

        $this->provider()->createEnvelope($withSubject);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/signature_requests')
            && $request->method() === 'POST'
            && $request['email_notification']['custom_text']['request_subject'] === 'Firma QAV - ore 19:58 ROSSI'
            && $request['email_notification']['custom_text']['reminder_subject'] === 'Firma QAV - ore 19:58 ROSSI');

        Http::fake();
        $this->fakeHappyPath();
        $this->provider()->createEnvelope($envelope);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/signature_requests')
            && $request->method() === 'POST'
            && ! isset($request['email_notification']));
    }

    public function test_signers_are_created_in_position_order_even_if_given_reversed(): void
    {
        $this->fakeHappyPath();
        $envelope = $this->makeEnvelope();
        $reversed = new EnvelopeData(
            $envelope->name, $envelope->fileName, $envelope->pdf, array_reverse($envelope->signers), $envelope->expiresAt, $envelope->externalId,
        );

        $ref = $this->provider()->createEnvelope($reversed);

        $this->assertSame(['signer1' => 'sg-1', 'signer2' => 'sg-2'], $ref->signerRefs);
    }

    public function test_failure_on_second_signer_deletes_request_and_throws_invalid_envelope(): void
    {
        $count = 0;
        $this->useFake([
            '*/signature_requests/sr-1/signers' => function () use (&$count) {
                return ++$count === 1
                    ? Http::response(['id' => 'sg-1'], 201)
                    : Http::response(['detail' => 'Telefono non valido per anna@example.test +393397654321'], 422);
            },
            '*/signature_requests/sr-1/documents' => Http::response(['id' => 'doc-1'], 201),
            '*/signature_requests/sr-1' => Http::response('', 204),
            '*/signature_requests' => Http::response(['id' => 'sr-1'], 201),
        ]);

        try {
            $this->provider()->createEnvelope($this->makeEnvelope());
            $this->fail('Attesa InvalidEnvelopeException');
        } catch (InvalidEnvelopeException $e) {
            $this->assertStringNotContainsString('anna@example.test', $e->getMessage());
            $this->assertStringNotContainsString('393397654321', $e->getMessage());
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && $r->url() === self::BASE.'/signature_requests/sr-1');
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/activate'));
    }

    public function test_delete_failure_during_cleanup_is_ignored(): void
    {
        $this->useFake([
            '*/signature_requests/sr-1/documents' => Http::response(['detail' => 'pdf rotto'], 422),
            '*/signature_requests/sr-1' => Http::response('boom', 500),
            '*/signature_requests' => Http::response(['id' => 'sr-1'], 201),
        ]);

        $this->expectException(InvalidEnvelopeException::class);
        $this->provider()->createEnvelope($this->makeEnvelope());
    }

    public function test_401_maps_to_invalid_key_message_without_leaking_it(): void
    {
        $this->useFake(['*' => Http::response(['detail' => 'nope'], 401)]);

        try {
            $this->provider()->createEnvelope($this->makeEnvelope());
            $this->fail('Attesa ProviderUnavailableException');
        } catch (ProviderUnavailableException $e) {
            $this->assertSame('Chiave API Yousign non valida', $e->getMessage());
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function test_403_maps_to_invalid_key_message(): void
    {
        $this->useFake(['*' => Http::response([], 403)]);

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionMessage('Chiave API Yousign non valida');
        $this->provider()->envelopeStatus('sr-1');
    }

    public function test_404_maps_to_not_found(): void
    {
        $this->useFake(['*' => Http::response([], 404)]);

        $this->expectException(EnvelopeNotFoundException::class);
        $this->provider()->envelopeStatus('sr-x');
    }

    public function test_429_maps_to_unavailable(): void
    {
        $this->useFake(['*' => Http::response([], 429)]);

        $this->expectException(ProviderUnavailableException::class);
        $this->provider()->envelopeStatus('sr-1');
    }

    public function test_500_is_retried_once_then_succeeds(): void
    {
        $this->useFake([
            '*/signature_requests/sr-1/signers' => Http::response([]),
            '*/signature_requests/sr-1' => Http::sequence()->push('', 500)->push(['id' => 'sr-1', 'status' => 'done']),
        ]);

        $status = $this->provider()->envelopeStatus('sr-1');

        $this->assertSame(SignatureRequestStatus::Signed, $status->status);
        Http::assertSentCount(3);
    }

    public function test_persistent_500_maps_to_unavailable_after_one_retry(): void
    {
        $this->useFake(['*' => Http::response('', 503)]);

        try {
            $this->provider()->envelopeStatus('sr-1');
            $this->fail('Attesa ProviderUnavailableException');
        } catch (ProviderUnavailableException) {
            Http::assertSentCount(2);
        }
    }

    public function test_client_errors_are_not_retried(): void
    {
        $this->useFake(['*' => Http::response(['detail' => 'x'], 422)]);

        try {
            $this->provider()->envelopeStatus('sr-1');
            $this->fail('Attesa InvalidEnvelopeException');
        } catch (InvalidEnvelopeException) {
            Http::assertSentCount(1);
        }
    }

    public function test_connection_error_maps_to_unavailable(): void
    {
        $this->useFake(['*' => fn () => throw new ConnectionException('timeout')]);

        $this->expectException(ProviderUnavailableException::class);
        $this->provider()->envelopeStatus('sr-1');
    }

    public function test_invalid_detail_is_truncated_to_200_characters(): void
    {
        $this->useFake(['*' => Http::response(['detail' => str_repeat('a', 500)], 400)]);

        try {
            $this->provider()->envelopeStatus('sr-1');
            $this->fail('Attesa InvalidEnvelopeException');
        } catch (InvalidEnvelopeException $e) {
            $this->assertLessThanOrEqual(200, mb_strlen($e->getMessage()));
        }
    }

    /**
     * @return array<string, array{0: string, 1: SignatureRequestStatus}>
     */
    public static function statusProvider(): array
    {
        return [
            'ongoing' => ['ongoing', SignatureRequestStatus::Sent],
            'done' => ['done', SignatureRequestStatus::Signed],
            'expired' => ['expired', SignatureRequestStatus::Expired],
            'canceled' => ['canceled', SignatureRequestStatus::Cancelled],
            'rejected' => ['rejected', SignatureRequestStatus::Declined],
            'draft' => ['draft', SignatureRequestStatus::Sent],
        ];
    }

    #[DataProvider('statusProvider')]
    public function test_envelope_status_mapping(string $remote, SignatureRequestStatus $expected): void
    {
        $this->useFake([
            '*/signers' => Http::response([]),
            '*/signature_requests/sr-1' => Http::response(['id' => 'sr-1', 'status' => $remote]),
        ]);

        $this->assertSame($expected, $this->provider()->envelopeStatus('sr-1')->status);
    }

    public function test_signer_status_mapping_and_signed_date(): void
    {
        $this->useFake([
            '*/signers' => Http::response([
                ['id' => 'a', 'status' => 'signed', 'signed_at' => '2026-10-09T10:00:00+00:00'],
                ['id' => 'b', 'status' => 'declined'],
                ['id' => 'c', 'status' => 'notified'],
                ['id' => 'd', 'status' => 'initiated'],
                ['id' => 'e', 'status' => 'whatever'],
            ]),
            '*/signature_requests/sr-1' => Http::response(['status' => 'ongoing']),
        ]);

        $signers = collect($this->provider()->envelopeStatus('sr-1')->signers)->keyBy('providerSignerRef');

        $this->assertSame(SignerStatus::Signed, $signers['a']->status);
        $this->assertSame('2026-10-09', $signers['a']->signedAt->toDateString());
        $this->assertSame('12:00', $signers['a']->signedAt->format('H:i'));
        $this->assertSame(SignerStatus::Declined, $signers['b']->status);
        $this->assertSame(SignerStatus::Notified, $signers['c']->status);
        $this->assertSame(SignerStatus::Notified, $signers['d']->status);
        $this->assertSame(SignerStatus::Waiting, $signers['e']->status);
    }

    public function test_download_pdf(): void
    {
        $this->useFake(['*' => Http::response('%PDF-1.4 data', 200, ['Content-Type' => 'application/pdf'])]);

        $this->assertSame('%PDF-1.4 data', $this->provider()->downloadSigned('sr-1'));
    }

    public function test_download_zip_extracts_first_pdf(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('leggimi.txt', 'ciao');
        $zip->addFromString('firmato.pdf', '%PDF-zip');
        $zip->close();
        $content = (string) file_get_contents($path);
        unlink($path);
        $this->useFake(['*' => Http::response($content, 200, ['Content-Type' => 'application/zip'])]);

        $this->assertSame('%PDF-zip', $this->provider()->downloadSigned('sr-1'));
    }

    public function test_download_other_content_type_throws(): void
    {
        $this->useFake(['*' => Http::response('<html>', 200, ['Content-Type' => 'text/html'])]);

        $this->expectException(InvalidEnvelopeException::class);
        $this->provider()->downloadSigned('sr-1');
    }

    public function test_cancel_sends_reason(): void
    {
        $this->useFake(['*' => Http::response([], 200)]);

        $this->provider()->cancelEnvelope('sr-1');

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && $r->url() === self::BASE.'/signature_requests/sr-1/cancel'
            && $r['reason'] === 'other');
    }

    private function webhook(array $payload, ?string $signature = null, bool $tamper = false): Request
    {
        $body = json_encode($payload);
        $signature ??= 'sha256='.hash_hmac('sha256', $body, self::SECRET);
        if ($tamper) {
            $body = str_replace('sr-1', 'sr-2', $body);
        }

        return Request::create('/x', 'POST', [], [], [], ['HTTP_X_YOUSIGN_SIGNATURE_256' => $signature], $body);
    }

    private function payload(string $event = 'signature_request.done'): array
    {
        return ['event_id' => 'ev-1', 'event_name' => $event, 'data' => ['signature_request' => ['id' => 'sr-1']]];
    }

    public function test_webhook_valid_signature_returns_event(): void
    {
        $event = $this->provider()->parseWebhook($this->webhook($this->payload()));

        $this->assertSame('sr-1', $event->providerRef);
        $this->assertSame('signature_request.done', $event->type);
        $this->assertSame('ev-1', $event->eventId);
    }

    public function test_webhook_tampered_body_returns_null(): void
    {
        $this->assertNull($this->provider()->parseWebhook($this->webhook($this->payload(), null, true)));
    }

    public function test_webhook_without_header_returns_null(): void
    {
        $request = Request::create('/x', 'POST', [], [], [], [], json_encode($this->payload()));

        $this->assertNull($this->provider()->parseWebhook($request));
    }

    public function test_webhook_with_empty_secret_returns_null(): void
    {
        config(['signature.yousign.webhook_secret' => '']);
        $body = json_encode($this->payload());
        $request = Request::create('/x', 'POST', [], [], [], ['HTTP_X_YOUSIGN_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, '')], $body);

        $this->assertNull($this->provider()->parseWebhook($request));
    }

    public function test_webhook_unknown_event_is_ignored(): void
    {
        $event = $this->provider()->parseWebhook($this->webhook($this->payload('signer.link_opened')));

        $this->assertSame('ignored', $event->type);
    }

    public function test_post_500_is_sent_exactly_once_and_maps_to_unavailable(): void
    {
        $this->useFake(['*' => Http::response('', 500)]);

        try {
            $this->provider()->createEnvelope($this->makeEnvelope());
            $this->fail('Attesa ProviderUnavailableException');
        } catch (ProviderUnavailableException) {
            Http::assertSentCount(1);
        }
    }

    public function test_post_connection_error_is_not_retried(): void
    {
        $calls = 0;
        $this->useFake(['*' => function () use (&$calls) {
            $calls++;
            throw new ConnectionException('timeout');
        }]);

        try {
            $this->provider()->cancelEnvelope('sr-1');
            $this->fail('Attesa ProviderUnavailableException');
        } catch (ProviderUnavailableException) {
            $this->assertSame(1, $calls);
        }
    }

    public function test_422_message_never_contains_personal_data_but_keeps_field_names(): void
    {
        $this->useFake(['*' => Http::response([
            'detail' => 'Il firmatario Mario Rossi mario@example.test +393381234567 non e valido',
            'invalid_params' => [
                ['name' => 'info.phone_number', 'reason' => 'Mario Rossi'],
                ['name' => 'Mario Rossi <script>', 'reason' => 'x'],
            ],
        ], 422)]);

        try {
            $this->provider()->envelopeStatus('sr-1');
            $this->fail('Attesa InvalidEnvelopeException');
        } catch (InvalidEnvelopeException $e) {
            $this->assertSame('Dati rifiutati dal provider (codice 422): info.phone_number', $e->getMessage());
            foreach (['Mario', 'Rossi', 'example.test', '3381234567'] as $needle) {
                $this->assertStringNotContainsString($needle, $e->getMessage());
            }
        }
    }

    public function test_other_4xx_map_to_invalid_envelope(): void
    {
        $this->useFake(['*' => Http::response([], 409)]);

        try {
            $this->provider()->cancelEnvelope('sr-1');
            $this->fail('Attesa InvalidEnvelopeException');
        } catch (InvalidEnvelopeException $e) {
            $this->assertSame('Dati rifiutati dal provider (codice 409)', $e->getMessage());
        }
    }

    public function test_missing_request_id_throws_invalid_envelope_without_cleanup(): void
    {
        $this->useFake(['*' => Http::response(['id' => ''], 201)]);

        try {
            $this->provider()->createEnvelope($this->makeEnvelope());
            $this->fail('Attesa InvalidEnvelopeException');
        } catch (InvalidEnvelopeException) {
            Http::assertSentCount(1);
            Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'DELETE');
        }
    }

    public function test_missing_signer_id_throws_and_cleans_up(): void
    {
        $this->useFake([
            '*/signature_requests/sr-1/signers' => Http::response(['status' => 'ok'], 201),
            '*/signature_requests/sr-1/documents' => Http::response(['id' => 'doc-1'], 201),
            '*/signature_requests/sr-1' => Http::response('', 204),
            '*/signature_requests' => Http::response(['id' => 'sr-1'], 201),
        ]);

        $this->expectException(InvalidEnvelopeException::class);
        try {
            $this->provider()->createEnvelope($this->makeEnvelope());
        } finally {
            Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/signature_requests/sr-1'));
        }
    }

    public function test_activate_is_sent_without_json_body(): void
    {
        $this->fakeHappyPath();

        $this->provider()->createEnvelope($this->makeEnvelope());

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/activate') && $r->body() === '');
    }

    public function test_download_sends_pdf_zip_accept_header(): void
    {
        $this->useFake(['*' => Http::response('%PDF', 200, ['Content-Type' => 'application/pdf'])]);

        $this->provider()->downloadSigned('sr-1');

        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Accept', 'application/pdf, application/zip'));
    }

    public function test_webhook_valid_signature_without_request_id_is_ignored(): void
    {
        $event = $this->provider()->parseWebhook($this->webhook(['event_id' => 'ev-1', 'event_name' => 'signature_request.done', 'data' => []]));

        $this->assertSame('ignored', $event->type);
    }

    public function test_manager_builds_yousign_from_config(): void
    {
        $this->assertInstanceOf(YousignSignatureProvider::class, (new SignatureProviderManager)->provider('yousign'));
        $this->assertSame('yousign', $this->provider()->name());
        $this->assertSame(['email', 'phone'], $this->provider()->requiredContactFields());
    }
}
