<?php

namespace Unico\Core\Signature\Jobs;

use Unico\Core\Models\SignatureRequest;
use Unico\Core\Signature\SignatureRequestService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Riallinea una richiesta di firma allo stato presso il provider.
 */
class ReconcileSignatureRequest implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 120;

    public function __construct(public int $signatureRequestId) {}

    public function uniqueId(): string
    {
        return (string) $this->signatureRequestId;
    }

    public function handle(SignatureRequestService $service): void
    {
        $request = SignatureRequest::query()->find($this->signatureRequestId);

        if ($request === null) {
            return;
        }

        $service->reconcile($request);
    }
}
