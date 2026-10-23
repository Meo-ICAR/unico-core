<?php

namespace Unico\Core\Seeders;

use Illuminate\Database\Seeder;
use Unico\Core\Models\EmployeeType;

/**
 * Ruoli (uffici) dell'organigramma e funzionigramma del Mediatore Creditizio (modello organizzativo D.M. 31/2014). Le funzioni
 * di controllo (Internal Audit, Compliance, AML, DPO) sono esternalizzate: possono essere ricoperte anche da un esterno.
 * Il responsabile gerarchico (`managed_by_code`) segue le frecce dell'organigramma; i controlli rispondono al CdA.
 * Idempotente: riallinea nomi e dati dei ruoli già presenti (anche in `employee_roles` di chi li ricopre). Si gestisce in UnicoOAM
 * (Ruoli); `php artisan db:seed --class="Unico\Core\Seeders\OrganigrammaSeeder"`.
 */
class OrganigrammaSeeder extends Seeder
{
    /**
     * codice => [nome del ruolo, nome dell'ufficio, macro area, tipologia, esternalizzabile, codice del responsabile].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: ?string}>
     */
    public const ORGANIGRAMMA = [
        'CDA' => ['cda', 'Consiglio d\'Amministrazione', 'Governance', 'Strategica', 'no', null],
        'AD' => ['amministratore delegato', 'Amministratore Delegato', 'Governance', 'Strategica', 'no', 'CDA'],
        'AFFARI' => ['affari societari e contabilità', 'Affari societari e contabilità', 'Supporto', 'Supporto', 'no', 'AD'],
        'ORG-RU' => ['organizzazione e risorse umane', 'Organizzazione e Risorse umane', 'Supporto', 'Supporto', 'no', 'AD'],
        'SEGRETERIA' => ['segreteria amministrativa', 'Segreteria amministrativa e di direzione', 'Supporto', 'Operativa', 'no', 'ORG-RU'],
        'IT' => ['it', 'Information Technology', 'Supporto', 'Supporto', 'no', 'SEGRETERIA'],
        'COMMERCIALE' => ['commerciale', 'Area Commerciale', 'Business / Commerciale', 'Operativa', 'no', 'SEGRETERIA'],
        'SEGR-RETE' => ['segreteria di rete', 'Segreteria di Rete', 'Business / Commerciale', 'Operativa', 'no', 'COMMERCIALE'],
        'BUS-BO' => ['back office', 'Back Office Prodotti', 'Business / Commerciale', 'Operativa', 'no', 'SEGR-RETE'],
        'BUS-RETE-EXT' => ['rete collaboratori', 'Rete Collaboratori', 'Business / Commerciale', 'Operativa', 'no', 'BUS-BO'],
        'INT-AUDIT' => ['audit', 'Internal Audit', 'Controlli (III Livello)', 'Controllo', 'yes', 'CDA'],
        'COMPLIANCE' => ['compliance', 'Funzione Compliance', 'Controlli (II Livello)', 'Controllo', 'yes', 'CDA'],
        'CTRL-AML' => ['antiriciclaggio', 'Funzione Antiriciclaggio (AML)', 'Controlli (II Livello)', 'Controllo', 'yes', 'CDA'],
        'DPO' => ['dpo', 'Data Protection Officer', 'Controlli / Privacy', 'Controllo', 'yes', 'CDA'],
    ];

    public function run(): void
    {
        foreach (static::ORGANIGRAMMA as $code => [$name, $office, $area, $type, $outsourcable, $managedBy]) {
            $role = EmployeeType::query()->where('code', $code)->first()
                ?? EmployeeType::query()->where('name', $name)->first()
                ?? new EmployeeType(['name' => $name]);

            if ($role->exists) {
                $role->renameTo($name);
            }

            $role->fill([
                'code' => $code,
                'description' => $office,
                'macro_area' => $area,
                'type' => $type,
                'outsourcable_status' => $outsourcable,
                'managed_by_code' => $managedBy,
            ])->save();
        }

    }
}
