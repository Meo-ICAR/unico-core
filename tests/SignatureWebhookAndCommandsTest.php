<?php

namespace Unico\Core\Tests;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Unico\Core\Console\SignatureCheckCommand;
use Unico\Core\Console\SignatureSyncCommand;
use Unico\Core\Enums\SignatureRequestStatus;
use Unico\Core\Http\Controllers\SignatureWebhookController;
use Unico\Core\Models\SignatureRequest;
use Unico\Core\Signature\FakeSignatureProvider;
use Unico\Core\Signature\Jobs\ReconcileSignatureRequest;
use Unico\Core\Signature\SignatureProviderManager;
use Unico\Core\Signature\SignatureRequestService;
use Unico\Core\Tests\Support\CoreDatabase;
use Unico\Core\Tests\Support\SignatureTestKit;

class SignatureWebhookAndCommandsTest extends TestCase
{
    use SignatureTestKit;

    /** @var array<int, object> */
    private array $dispatched = [];

    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        $this->bootSignature();
        CoreDatabase::truncate('signature_request_signers', 'signature_requests', 'pdf_modules', 'documents', 'document_types');
        config(['app.env' => 'testing']);

        $this->dispatched = [];
        $recorder = new class($this->dispatched)
        {
            public function __construct(public array &$jobs) {}

            public function dispatch(object $job): void
            {
                $this->jobs[] = $job;
            }
        };
        Container::getInstance()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $recorder);
    }

    private function invoke(string $provider, array $payload, array $headers = []): int
    {
        $request = Request::create("/signature/webhook/{$provider}", 'POST', [], [], [], [], json_encode($payload));
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $service = $this->service();

        return (new SignatureWebhookController)($request, $provider, new SignatureProviderManager, $service)->getStatusCode();
    }

    private function sent(string $ref = 'fake-ref-1', array $attributes = []): SignatureRequest
    {
        $document = $this->documentWithModule();

        return SignatureRequest::create($attributes + [
            'document_id' => $document->id, 'provider' => 'fake', 'provider_ref' => $ref,
            'status' => SignatureRequestStatus::Sent, 'expires_at' => now()->addDays(7),
        ]);
    }

    public function test_wrong_signature_is_rejected_without_side_effects(): void
    {
        $request = $this->sent();

        $this->assertSame(401, $this->invoke('fake', ['ref' => 'fake-ref-1', 'type' => 'done', 'id' => '1']));
        $this->assertSame(401, $this->invoke('fake', ['ref' => 'fake-ref-1', 'type' => 'done', 'id' => '1'], ['X-Fake-Signature' => 'bad']));
        $this->assertSame([], $this->dispatched);
        $this->assertNull($request->refresh()->last_event_at);
    }

    public function test_only_the_active_provider_is_accepted(): void
    {
        $this->assertSame(404, $this->invoke('yousign', []));
        $this->assertSame(404, $this->invoke('sconosciuto', []));
    }

    public function test_fake_provider_is_never_accepted_in_production(): void
    {
        config(['app.env' => 'production']);

        $this->assertSame(404, $this->invoke('fake', ['ref' => 'x', 'type' => 'done', 'id' => '1'], ['X-Fake-Signature' => 'ok']));
    }

    public function test_valid_unknown_or_malformed_events_are_acknowledged_without_work(): void
    {
        $this->sent();

        $this->assertSame(204, $this->invoke('fake', ['ref' => 'altro', 'type' => 'done', 'id' => '1'], ['X-Fake-Signature' => 'ok']));
        $this->assertSame(204, $this->invoke('fake', ['qualcosa' => 'di strano'], ['X-Fake-Signature' => 'ok']));
        $this->assertSame([], $this->dispatched);
    }

    public function test_known_request_records_the_event_and_queues_a_reconcile(): void
    {
        $request = $this->sent();

        $this->assertSame(202, $this->invoke('fake', ['ref' => 'fake-ref-1', 'type' => 'done', 'id' => 'evt-1'], ['X-Fake-Signature' => 'ok']));

        $this->assertNotNull($request->refresh()->last_event_at);
        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(ReconcileSignatureRequest::class, $this->dispatched[0]);
        $this->assertSame($request->id, $this->dispatched[0]->signatureRequestId);
        $this->assertSame((string) $request->id, $this->dispatched[0]->uniqueId());
    }

    public function test_job_reconciles_through_the_service_and_ignores_missing_requests(): void
    {
        $provider = new FakeSignatureProvider;
        $service = $this->service($provider);
        $document = $this->documentWithModule();
        $service->originals[$document->id] = file_get_contents(__DIR__.'/Fixtures/pdf/modulo-prova.pdf');
        $request = $service->send($document, $this->signers());
        $provider->completeAll($request->provider_ref);

        (new ReconcileSignatureRequest($request->id))->handle($service);
        (new ReconcileSignatureRequest(999999))->handle($service);

        $this->assertSame(SignatureRequestStatus::Signed, $request->refresh()->status);
    }

    private function runCommand(object $command, array $input = []): array
    {
        $app = new class extends Container
        {
            public function runningUnitTests(): bool
            {
                return true;
            }
        };
        $app->instance(SignatureRequestService::class, $this->service());
        $app->instance(SignatureProviderManager::class, new SignatureProviderManager);
        $command->setLaravel($app);
        $output = new BufferedOutput;
        $code = $command->run(new ArrayInput($input), $output);

        return [$code, $output->fetch()];
    }

    public function test_sync_command_reconciles_stale_requests_and_expires_overdue_ones(): void
    {
        $provider = new FakeSignatureProvider;
        $service = $this->service($provider);
        $document = $this->documentWithModule();
        $service->originals[$document->id] = file_get_contents(__DIR__.'/Fixtures/pdf/modulo-prova.pdf');
        $stale = $service->send($document, $this->signers());
        $provider->completeAll($stale->provider_ref);

        [$code, $text] = $this->runCommand(new SignatureSyncCommand, ['--stale-minutes' => 0]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Riconciliate: 1', $text);
        $this->assertSame(SignatureRequestStatus::Signed, $stale->refresh()->status);
    }

    public function test_check_command_skips_the_fake_driver(): void
    {
        [$code, $text] = $this->runCommand(new SignatureCheckCommand);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('fake', $text);
    }

    public function test_check_command_reports_an_unconfigured_provider_as_failure(): void
    {
        config(['unico-core.signature.driver' => 'boh']);

        [$code, $text] = $this->runCommand(new SignatureCheckCommand);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('KO', $text);
    }
}
