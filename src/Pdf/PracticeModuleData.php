<?php

namespace Unico\Core\Pdf;

use Unico\Core\Models\Branch;
use Unico\Core\Models\Client;
use Unico\Core\Models\Document;
use Unico\Core\Models\Pratica;

/** Dati della pratica e del cliente con cui compilare un modulo PDF da stampare. */
class PracticeModuleData
{
    /** Codici di DocumentType che identificano un documento d'identità. */
    public const IDENTITY_TYPE_CODES = ['CARTA_IDENTITA', 'DOCUMENTO_IDENTIFICATIVO'];

    /** Nomi di DocumentType senza codice ma validi come documento d'identità. */
    public const IDENTITY_TYPE_NAMES = ['Carta di Identità o Patente & cert. Residenza', 'Carta di Identità', 'Patente di Guida'];

    /** @param  iterable<ModuleDataProvider>  $providers */
    public function __construct(private iterable $providers = []) {}

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
        $pratica->loadMissing(['fornitori', 'company', 'praticaClients.client.employer.branches', 'praticaClients.client.branches', 'praticaClients.client.legalRepresentative', 'praticaClients.client.documents.documentType']);
        $client = $this->findClient($pratica);
        $link = $pratica->praticaClients->first();

        $agent = $pratica->fornitori;
        $employer = $client?->employer;
        $company = $pratica->company;
        $representative = $client?->legalRepresentative;
        $branch = $client === null ? null : $this->mainBranch($client);
        $employerBranch = $employer === null ? null : $this->mainBranch($employer);
        $identity = $client === null ? null : $this->identityDocument($client);
        $nominativo = trim(($client?->name ?? $pratica->cognome_cliente ?? '').' '.($client?->first_name ?? $pratica->nome_cliente ?? ''));

        $values = [
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
            'agent.oam' => $agent?->oam,
            'agent.oam_at' => $agent?->oam_at,
            'agent.numero_iscrizione_rui' => $agent?->numero_iscrizione_rui,
            'agent.indirizzo_completo' => $agent === null ? null : $this->formatAddress($agent->indirizzo, $agent->cap, $agent->comune, $agent->prov),
            'employer.name' => $employer?->name,
            'employer.vat_number' => $employer?->vat_number,
            'employer.address' => $employerBranch === null ? null : $this->fullAddress($employerBranch),
            'company.name' => $company?->name,
            'company.vat_number' => $company?->vat_number,
            'company.oam' => $company?->oam,
            'company.oam_at' => $company?->oam_at,
            'client.pec' => $client?->pec,
            'client.ateco_code' => $client?->ateco_code,
            'client.cciaa_registration' => $client?->cciaa_registration,
            'client.legal_form' => $client?->legal_form,
            'client.profession' => $client?->profession,
            'client.employment_typology' => $client?->employment_typology,
            'client.hiring_date' => $client?->hiring_date,
            'client.is_pep' => $client?->is_pep,
            'client.consent_general' => $client === null ? null : $client->general_consent_at !== null,
            'client.consent_sic' => $client === null ? null : $client->consent_sic_at !== null,
            'client.consent_marketing' => $client === null ? null : $client->consent_marketing_at !== null,
            'client.consent_profiling' => $client === null ? null : $client->consent_profiling_at !== null,
            'legal_rep.nominativo' => $representative === null ? null : trim(($representative->name ?? '').' '.($representative->first_name ?? '')),
            'legal_rep.email' => $representative?->email,
            'legal_rep.phone' => $representative?->phone,
            'branch.address' => $branch?->address,
            'branch.street_number' => $branch?->street_number,
            'branch.city' => $branch?->city,
            'branch.zip_code' => $branch?->zip_code,
            'branch.province' => $branch?->province,
            'branch.indirizzo_completo' => $branch === null ? null : $this->fullAddress($branch),
            'document.identity.type' => $identity?->documentType?->name,
            'document.identity.docnumber' => $identity?->docnumber,
            'document.identity.emitted_by' => $identity?->emitted_by,
            'document.identity.emitted_at' => $identity?->emitted_at,
            'document.identity.expires_at' => $identity?->expires_at,
            'pratica_client.role' => $link?->role,
            'pratica_client.purpose' => $link?->purpose_of_relationship,
            'pratica_client.funds_origin' => $link?->funds_origin,
            'mediation.fee_percent' => $pratica->mediation_fee_percent,
            'mediation.fee_amount' => $pratica->mediation_fee_amount,
            'mediation.fee_terms' => $pratica->mediation_fee_terms,
            'mediation.istruttoria_costs' => $pratica->istruttoria_costs,
            'mediation.offer_valid_until' => $pratica->offer_valid_until,
            'mediation.withdrawal_terms' => $pratica->withdrawal_terms,
        ];

        foreach ($this->providers as $provider) {
            $values += $provider->values($pratica, $client);
        }

        return new ResolvedModuleData($values);
    }

    /** Sede principale: la più recente tra quelle marcate `is_main_office`, altrimenti tra le non dismesse. */
    private function mainBranch(Client $client): ?Branch
    {
        $active = $client->branches->filter(fn (Branch $branch) => $branch->dismissed_at === null);
        $main = $active->where('is_main_office', true);

        return ($main->isEmpty() ? $active : $main)
            ->sortByDesc(fn (Branch $branch) => [$branch->created_at?->getTimestamp() ?? 0, (int) $branch->id])
            ->first();
    }

    private function fullAddress(Branch $branch): ?string
    {
        return $this->formatAddress(trim(($branch->address ?? '').' '.($branch->street_number ?? '')), $branch->zip_code, $branch->city, $branch->province);
    }

    private function formatAddress(?string $street, ?string $zipCode, ?string $city, ?string $province): ?string
    {
        $place = trim(trim(($zipCode ?? '').' '.($city ?? '')).' '.(filled($province) ? '('.$province.')' : ''));
        $address = implode(', ', array_filter([trim((string) $street), $place]));

        return $address === '' ? null : $address;
    }

    /** Ultimo documento d'identità non scaduto del cliente (per data di rilascio); il certificato di residenza non vale. */
    private function identityDocument(Client $client): ?Document
    {
        return $client->documents
            ->filter(function (Document $document): bool {
                $type = $document->documentType;

                if ($type === null) {
                    return false;
                }

                $isIdentity = in_array($type->code, self::IDENTITY_TYPE_CODES, true) || in_array($type->name, self::IDENTITY_TYPE_NAMES, true);
                $notExpired = $document->expires_at === null || $document->expires_at->greaterThanOrEqualTo(today());

                return $isIdentity && $notExpired && ! str_contains(mb_strtolower((string) $document->name), 'residenza');
            })
            ->sortByDesc(fn (Document $document) => [$document->emitted_at?->getTimestamp() ?? 0, $document->created_at?->getTimestamp() ?? 0])
            ->first();
    }
}
