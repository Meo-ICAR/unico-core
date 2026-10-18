<?php

namespace Unico\Core\Pdf;

use Unico\Core\Models\Client;
use Unico\Core\Models\Pratica;

/**
 * Valori aggiuntivi per compilare i moduli, di dati che il pacchetto non ha (es. il questionario KYC di unicofinance).
 * Le app registrano l'implementazione con il tag `unico-core.module-data-providers`; le chiavi sono stringhe (`kyc.pep_status`...)
 * e i valori sono quelli grezzi, come per `ResolvedModuleData`. Le chiavi del pacchetto non si sovrascrivono.
 */
interface ModuleDataProvider
{
    /**
     * @return array<string, mixed>
     */
    public function values(Pratica $pratica, ?Client $client): array;
}
