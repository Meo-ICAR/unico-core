<?php

namespace Unico\Core\Console;

use Illuminate\Console\Command;
use Unico\Core\Events\MailAccountsUnhealthy;
use Unico\Core\Models\MailAccount;

/**
 * Segnala le caselle attive ferme da troppo e i token OAuth2 senza refresh token in scadenza.
 * Chi deve essere avvisato lo decide l'app, ascoltando MailAccountsUnhealthy.
 */
class MailHealthCommand extends Command
{
    protected $signature = 'unico-core:mail-health {--stale-hours=24 : Ore oltre le quali una casella è considerata ferma}';

    protected $description = 'Controlla le caselle ferme o con token OAuth2 in scadenza';

    public function handle(): int
    {
        $staleHours = (int) $this->option('stale-hours');
        $threshold = now()->subHours($staleHours);

        $accounts = MailAccount::query()->active()->get();

        $stale = $accounts->filter(fn (MailAccount $a) => $a->last_synced_at === null || $a->last_synced_at->lt($threshold))->values();

        $expiring = $accounts->filter(fn (MailAccount $a) => $a->auth_type === 'oauth2'
            && $a->token_expires_at
            && $a->token_expires_at->lt(now()->addDay())
            && blank($a->refresh_token))->values();

        if ($stale->isEmpty() && $expiring->isEmpty()) {
            $this->info('Tutte le caselle attive sono in salute.');

            return self::SUCCESS;
        }

        foreach ($stale as $a) {
            $this->warn("Ferma da troppo: [{$a->name}] ultima sync ".($a->last_synced_at?->diffForHumans() ?? 'mai'));
        }
        foreach ($expiring as $a) {
            $this->warn("Token OAuth in scadenza senza refresh token: [{$a->name}]");
        }

        event(new MailAccountsUnhealthy($stale, $expiring, $staleHours));

        return self::SUCCESS;
    }
}
