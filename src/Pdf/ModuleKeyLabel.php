<?php

namespace Unico\Core\Pdf;

use Illuminate\Support\Str;

/** Nome leggibile di una chiave dati dei moduli PDF (es. `kyc.owner1.birth_date` → «Titolare effettivo 1: data di nascita»). */
class ModuleKeyLabel
{
    private const GROUPS = [
        'client.address' => 'Indirizzo del cliente',
        'client' => 'Cliente',
        'agent' => 'Agente',
        'document.identity' => 'Documento d\'identità',
        'kyc.executor' => 'Esecutore',
        'compenso_cliente' => 'Compenso',
        'pratica' => 'Pratica',
        'kyc' => 'KYC',
    ];

    private const FIELDS = [
        'name' => 'cognome o ragione sociale', 'first_name' => 'nome', 'nominativo' => 'nominativo', 'tax_code' => 'codice fiscale',
        'birth_date' => 'data di nascita', 'birth_place' => 'luogo di nascita', 'citizenship' => 'cittadinanza', 'sex' => 'sesso',
        'email' => 'email', 'pec' => 'PEC', 'phone' => 'telefono', 'tel' => 'telefono',
        'address' => 'indirizzo', 'full' => 'indirizzo completo', 'line' => 'indirizzo', 'street' => 'via', 'number' => 'numero civico',
        'city' => 'città', 'province' => 'provincia', 'zip' => 'CAP', 'zip_code' => 'CAP',
        'doc_type' => 'tipo documento', 'doc_number' => 'numero documento', 'doc_issuer' => 'ente di rilascio', 'doc_issued_at' => 'data di rilascio', 'doc_expires_at' => 'data di scadenza documento',
        'type' => 'tipo', 'docnumber' => 'numero', 'emitted_at' => 'data di rilascio', 'emitted_by' => 'ente di rilascio',
        'pep_status' => 'esposizione politica (PEP)', 'criterion' => 'criterio di titolarità',
        'ateco_code' => 'codice ATECO', 'cciaa_registration' => 'iscrizione CCIAA', 'legal_nature' => 'natura giuridica',
        'consent_group' => 'consenso di gruppo', 'consent_marketing' => 'consenso marketing', 'consent_profiling' => 'consenso profilazione', 'consent_third_parties' => 'consenso a terzi',
        'activity_location' => 'luogo dell\'attività', 'activity_sector' => 'settore di attività', 'economic_activity' => 'attività economica',
        'financing_nature' => 'natura del finanziamento', 'financing_purpose' => 'scopo del finanziamento', 'geographic_area' => 'area geografica',
        'income_band' => 'fascia di reddito', 'wealth_band' => 'fascia di patrimonio', 'executor_link' => 'rapporto con l\'esecutore',
        'importo' => 'importo', 'proforma' => 'proforma', 'amount' => 'importo', 'denominazione_prodotto' => 'prodotto', 'oggi' => 'data odierna',
        'branch_city' => 'città della filiale', 'indirizzo_completo' => 'indirizzo completo',
    ];

    public static function for(string $key): string
    {
        foreach (self::GROUPS as $prefix => $group) {
            if ($key === $prefix || str_starts_with($key, $prefix.'.')) {
                return self::compose($group, substr($key, strlen($prefix) + 1));
            }
        }

        return Str::of($key)->replace(['.', '_'], ' ')->ucfirst()->toString();
    }

    /**
     * @param  iterable<int, string>  $keys
     * @return array<int, string>
     */
    public static function many(iterable $keys): array
    {
        return collect($keys)->map(fn (string $key): string => self::for($key))->unique()->values()->all();
    }

    private static function compose(string $group, string $rest): string
    {
        if (preg_match('/^owner(\d)\.(.+)$/', $rest, $match) === 1) {
            return 'Titolare effettivo '.$match[1].': '.self::field($match[2]);
        }

        return $rest === '' ? $group : $group.': '.self::field($rest);
    }

    private static function field(string $field): string
    {
        $last = Str::afterLast($field, '.');

        return self::FIELDS[$field] ?? self::FIELDS[$last] ?? str_replace('_', ' ', $field);
    }
}
