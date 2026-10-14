<?php

namespace Unico\Core\Console;

use Unico\Core\Signature\SignatureProviderManager;
use Unico\Core\Signature\YousignSignatureProvider;
use Illuminate\Console\Command;
use Throwable;

class SignatureCheckCommand extends Command
{
    protected $signature = 'signature:check';

    protected $description = 'Verifica in sola lettura la raggiungibilita e le credenziali del provider di firma';

    public function handle(SignatureProviderManager $manager): int
    {
        $driver = (string) config('unico-core.signature.driver');

        if ($driver === 'fake') {
            $this->info('Il driver di firma attivo e\' fake: nessuna verifica da eseguire.');

            return self::SUCCESS;
        }

        try {
            $provider = $manager->provider($driver);
        } catch (Throwable) {
            $this->error("Provider: {$driver} · esito: KO (provider non configurato)");

            return self::FAILURE;
        }

        if (! $provider instanceof YousignSignatureProvider) {
            $this->error("Provider: {$driver} · esito: KO (verifica non supportata)");

            return self::FAILURE;
        }

        $prefix = "Provider: yousign · URL: {$provider->baseUrl()} · esito: ";

        try {
            $status = $provider->ping();
        } catch (Throwable) {
            $this->error($prefix.'KO (provider non raggiungibile)');

            return self::FAILURE;
        }

        if ($status >= 200 && $status < 300) {
            $this->info($prefix."OK ({$status})");

            return self::SUCCESS;
        }

        $this->error($prefix."KO ({$status})");

        return self::FAILURE;
    }
}
