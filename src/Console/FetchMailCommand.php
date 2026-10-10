<?php

namespace Unico\Core\Console;

use Illuminate\Console\Command;
use Unico\Core\Mail\Jobs\FetchMailAccount;
use Unico\Core\Models\MailAccount;

class FetchMailCommand extends Command
{
    protected $signature = 'unico-core:mail-fetch {account? : ID di una casella; senza, tutte quelle attive} {--sync : Esegue subito invece di mettere in coda}';

    protected $description = 'Scarica le email delle caselle attive';

    public function handle(): int
    {
        $accounts = MailAccount::query()
            ->active()
            ->when($this->argument('account'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        if ($accounts->isEmpty()) {
            $this->warn('Nessuna casella attiva trovata.');

            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            $this->option('sync') ? FetchMailAccount::dispatchSync($account) : FetchMailAccount::dispatch($account);
            $this->line(($this->option('sync') ? 'Scaricata' : 'In coda').": {$account->email_address}");
        }

        return self::SUCCESS;
    }
}
