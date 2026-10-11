<?php

namespace Unico\Core\Bridge\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use Unico\Core\Bridge\ArtisanExecutor;
use Unico\Core\Models\CommandRun;

/**
 * Esegue in background un comando del bridge e ne registra l'esito nel `CommandRun`. Due lanci dello stesso comando non si
 * sovrappongono: il secondo risulta `skipped`. Un codice di uscita diverso da zero fa ritentare con attesa crescente.
 */
class RunBridgeCommand implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    public function __construct(public int $runId)
    {
        if ($queue = config('unico-core.bridge.queue')) {
            $this->onQueue($queue);
        }
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(ArtisanExecutor $executor): void
    {
        $run = CommandRun::query()->find($this->runId);

        if ($run === null) {
            return;
        }

        $lock = Cache::lock("bridge-command:{$run->command}", $this->timeout);

        if (! $lock->get()) {
            $run->update(['status' => CommandRun::SKIPPED, 'finished_at' => now(), 'output' => 'Un\'esecuzione precedente dello stesso comando è ancora in corso.']);

            return;
        }

        try {
            $run->update(['status' => CommandRun::RUNNING, 'attempts' => $run->attempts + 1, 'started_at' => now(), 'finished_at' => null]);

            $options = [];
            foreach ((array) $run->options as $name => $value) {
                $options['--'.$name] = $value;
            }

            [$exitCode, $output] = $executor->run($run->command, $options);
            $output = mb_substr($output, -4000);

            if ($exitCode === 0) {
                $run->update(['status' => CommandRun::SUCCEEDED, 'exit_code' => 0, 'output' => $output, 'finished_at' => now()]);

                return;
            }

            // Fallito: finché restano tentativi lo stato è «queued», così chi controlla sa che ritenterà.
            $run->update(['status' => $this->attempts() >= $this->tries ? CommandRun::FAILED : CommandRun::QUEUED, 'exit_code' => $exitCode, 'output' => $output, 'finished_at' => $this->attempts() >= $this->tries ? now() : null]);

            throw new RuntimeException("Comando '{$run->command}' terminato con exit code {$exitCode}.");
        } finally {
            $lock->release();
        }
    }

    public function failed(Throwable $exception): void
    {
        $run = CommandRun::query()->find($this->runId);

        if ($run !== null && ! $run->isFinished()) {
            $run->update(['status' => CommandRun::FAILED, 'output' => mb_substr($exception->getMessage(), 0, 1000), 'finished_at' => now()]);
        }

        Log::error("Comando del bridge #{$this->runId} non riuscito.", ['error' => $exception->getMessage()]);
    }
}
