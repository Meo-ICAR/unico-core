<?php

namespace Unico\Core\Documents\Reminders;

use Illuminate\Database\Eloquent\Model;
use Unico\Core\Models\Client;
use Unico\Core\Models\Pratica;

/**
 * Aiuti per trovare i soggetti di una pratica (richiedente, datore di lavoro, pratica del cliente); la scelta di chi ha in carico
 * un documento è di `DocumentResponsibleResolver`, e i solleciti vanno a chi ha il documento in carico.
 */
class DocumentRequestRouter
{
    /** Il datore di lavoro entra solo nelle pratiche di cessione e delega. */
    public function involvesEmployer(Pratica $pratica): bool
    {
        return in_array($pratica->tipoprodotto?->name, ['Cessione', 'Delega'], true);
    }

    /** Il richiedente: il cliente proprietario del documento, o quello con ruolo richiedente se il documento è della pratica. */
    public function requesterOf(?Model $owner, Pratica $pratica): ?Client
    {
        if ($owner instanceof Client) {
            return $owner;
        }

        return $pratica->praticaClients()->where('role', 'richiedente')->with('client')->first()?->client;
    }

    /** La pratica del soggetto: lui stesso se è una pratica, altrimenti l'ultima pratica del cliente (stesso codice fiscale). */
    public function practiceOf(?Model $owner): ?Pratica
    {
        if ($owner instanceof Pratica) {
            return $owner;
        }

        if ($owner instanceof Client && filled($owner->tax_code)) {
            return Pratica::query()->where('codice_fiscale', $owner->tax_code)
                ->orderByRaw('data_inserimento_pratica IS NULL')->orderByDesc('data_inserimento_pratica')->orderByDesc('id')
                ->first();
        }

        return null;
    }
}
