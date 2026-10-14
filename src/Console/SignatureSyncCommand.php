<?php

namespace Unico\Core\Console;

use Unico\Core\Enums\SignatureRequestStatus;
use Unico\Core\Models\SignatureRequest;
use Unico\Core\Signature\SignatureRequestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SignatureSyncCommand extends Command
{
    protected $signature = 'signature:sync {--limit= : Numero massimo di richieste} {--stale-minutes= : Minuti senza eventi/sincronizzazioni}';

    protected $description = 'Riconcilia le richieste di firma inviate rimaste senza aggiornamenti e chiude quelle scadute';

    public function handle(SignatureRequestService $service): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: config('unico-core.signature.sync.limit')));
        $staleMinutes = (int) ($this->option('stale-minutes') ?? config('unico-core.signature.sync.stale_minutes'));
        $threshold = now()->subMinutes($staleMinutes);

        $requests = SignatureRequest::query()
            ->where('status', SignatureRequestStatus::Sent)
            ->where(fn ($query) => $query->whereNull('last_event_at')->orWhere('last_event_at', '<', $threshold))
            ->where(fn ($query) => $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $threshold))
            ->orderByRaw('last_synced_at IS NOT NULL, last_synced_at ASC')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $reconciled = 0;
        $failed = 0;

        foreach ($requests as $request) {
            try {
                $service->reconcile($request);
                $reconciled++;
            } catch (\Throwable) {
                $failed++;
                Log::warning("Riconciliazione non riuscita per la richiesta di firma {$request->id}");
            }
        }

        $expired = $service->expireOverdue($limit);

        $this->info("Riconciliate: {$reconciled}, con errori: {$failed}, chiuse per scadenza: {$expired}.");

        return self::SUCCESS;
    }
}
