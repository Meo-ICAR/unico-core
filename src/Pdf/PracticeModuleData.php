<?php

namespace Unico\Core\Pdf;

use Unico\Core\Models\Client;
use Unico\Core\Models\Pratica;

/** Dati della pratica e del cliente con cui compilare un modulo PDF da stampare. */
class PracticeModuleData
{
    public function findClient(Pratica $pratica): ?Client
    {
        $client = $pratica->relationLoaded('praticaClients')
            ? $pratica->praticaClients->first()?->client
            : $pratica->praticaClients()->with('client')->first()?->client;

        if ($client !== null || blank($pratica->codice_fiscale)) {
            return $client;
        }

        return Client::query()
            ->where(fn ($query) => $query->where('tax_code', $pratica->codice_fiscale)->orWhere('vat_number', $pratica->codice_fiscale))
            ->first();
    }

    public function for(Pratica $pratica): ResolvedModuleData
    {
        $pratica->loadMissing(['fornitori', 'praticaClients.client.employer']);
        $client = $this->findClient($pratica);

        $agent = $pratica->fornitori;
        $employer = $client?->employer;
        $nominativo = trim(($client?->name ?? $pratica->cognome_cliente ?? '').' '.($client?->first_name ?? $pratica->nome_cliente ?? ''));

        return new ResolvedModuleData([
            'pratica.codice_pratica' => $pratica->codice_pratica,
            'pratica.amount' => $pratica->amount,
            'pratica.rata' => $pratica->rata,
            'pratica.nrate' => $pratica->nrate,
            'pratica.denominazione_banca' => $pratica->denominazione_banca,
            'pratica.abi' => $pratica->abi,
            'pratica.denominazione_prodotto' => $pratica->denominazione_prodotto,
            'pratica.data_inserimento_pratica' => $pratica->data_inserimento_pratica,
            'pratica.oggi' => now(),
            'client.name' => $client?->name ?? $pratica->cognome_cliente,
            'client.first_name' => $client?->first_name ?? $pratica->nome_cliente,
            'client.nominativo' => $nominativo !== '' ? $nominativo : null,
            'client.tax_code' => $client?->tax_code ?? $pratica->codice_fiscale,
            'client.vat_number' => $client?->vat_number,
            'client.email' => $client?->email,
            'client.phone' => $client?->phone,
            'client.salary' => $client?->salary,
            'client.iban' => $client?->iban,
            'client.is_person' => $client?->is_person,
            'client.birth_date' => $client?->birth_date,
            'client.birth_place' => $client?->birth_place,
            'client.sex' => $client?->sex,
            'client.citizenship' => $client?->citizenship,
            'agent.name' => $agent?->name ?? $pratica->denominazione_agente,
            'agent.email' => $agent?->email,
            'agent.tel' => $agent?->tel,
            'agent.cf' => $agent?->cf,
            'employer.name' => $employer?->name,
            'employer.vat_number' => $employer?->vat_number,
        ]);
    }
}
