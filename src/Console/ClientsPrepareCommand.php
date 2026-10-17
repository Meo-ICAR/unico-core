<?php

namespace Unico\Core\Console;

use Illuminate\Console\Command;
use Unico\Core\Models\Client;

class ClientsPrepareCommand extends Command
{
    protected $signature = 'unico-core:clients-prepare';

    protected $description = 'Crea, per ogni persona fisica che non li ha, residenza e domicilio e i due documenti d\'identità da raccogliere';

    public function handle(): int
    {
        $indirizzi = $documenti = $clienti = 0;

        Client::query()->where('is_person', true)->orderBy('id')->each(function (Client $client) use (&$indirizzi, &$documenti, &$clienti): void {
            $creati = $client->preparaAnagrafica();
            $indirizzi += $creati['indirizzi'];
            $documenti += $creati['documenti'];
            $clienti += $creati['indirizzi'] + $creati['documenti'] > 0 ? 1 : 0;
        });

        $this->info("Persone fisiche aggiornate: {$clienti}. Indirizzi creati: {$indirizzi}. Documenti creati: {$documenti}.");

        return self::SUCCESS;
    }
}
