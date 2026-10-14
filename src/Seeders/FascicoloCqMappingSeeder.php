<?php

namespace Unico\Core\Seeders;

use Illuminate\Database\Seeder;
use Unico\Core\Models\PdfModule;
use Unico\Core\Models\PdfModuleField;

/**
 * Collega ai dati della pratica i campi del «Fascicolo completo retail CQ compilabile» (modulo di unico-core).
 * Sono i 38 campi che compaiono davvero sulle pagine del PDF: gli altri 112 non sono collocati su nessuna pagina.
 * Ripetibile; non tocca i campi non elencati.
 */
class FascicoloCqMappingSeeder extends Seeder
{
    private const MODULO = 'Races VERS. 03_2026 - Fascicolo completo retail CQ compilabile';

    /** Casella «SI»/«NO» del consenso: segue il valore della chiave (NO = consenso assente). */
    private const SI = ['checkbox_on_value' => 'si'];

    /**
     * campo PDF => [chiave dati, formatter, obbligatorio]. Le caselle usano `consenso()`.
     *
     * @var array<string, array{0: string, 1: ?string, 2: bool}>
     */
    private const TESTI = [
        // Pag. 2: dichiarazione di consenso
        'Text1' => ['client.nominativo', null, true],
        'Text3' => ['client.tax_code', 'upper', true],
        'Text16' => ['pratica.oggi', 'date_it', false],
        // Pag. 6: collaboratore che entra in contatto con il cliente
        'dummyFieldName1' => ['agent.name', null, true],
        'Text2' => ['agent.indirizzo_completo', null, false],
        'dummyFieldName2' => ['agent.email', null, false],
        'Text4' => ['agent.tel', null, false],
        'Text5' => ['pratica.oggi', 'date_it', false],
        // Pag. 8: richiedente e incarico
        'nuovo1' => ['client.name', null, true],
        'nuovo2' => ['client.first_name', null, true],
        'nuovo3' => ['client.tax_code', 'upper', true],
        'nuovo4' => ['client.birth_place', null, true],
        'nuovo5' => ['client.birth_date', 'date_it', true],
        'Text6' => ['client.address.street', null, true],
        'Text8' => ['client.address.number', null, false],
        'Text9' => ['client.address.zip_code', null, true],
        'Text10' => ['client.address.city', null, true],
        'Text11' => ['client.address.province', null, true],
        'Text12' => ['client.phone', null, true],
        'Text13' => ['client.email', null, true],
        'testo 1' => ['pratica.denominazione_prodotto', null, true],
        'testo 2' => ['pratica.amount', 'money_it', true],
        // Pag. 10 e 11: date di firma e identificazione
        'nuovo6' => ['pratica.oggi', 'date_it', false],
        'Text17' => ['pratica.oggi', 'date_it', false],
        'Text18' => ['pratica.oggi', 'date_it', false],
        'Text19' => ['agent.name', null, false],
        // Luogo di firma: comune della sede dell'agente (vuoto se l'agente non ha una sede)
        'luogo' => ['agent.branch_city', null, false],
        'Text20' => ['pratica.oggi', 'date_it', false],
    ];

    /**
     * Consensi della pag. 2: casella SI di ogni riga => chiave del consenso. La casella NO resta vuota:
     * il rifiuto si segna a mano.
     *
     * @var array<string, string>
     */
    private const CONSENSI = [
        'Button4' => 'client.consent_marketing',       // nuovi prodotti/servizi del Titolare
        'Button7' => 'client.consent_group',           // prodotti di società del Gruppo
        'Button9' => 'client.consent_profiling',       // grado di soddisfazione
        'Button10' => 'client.consent_profiling',      // preferenze della clientela
        'Button12' => 'client.consent_third_parties',  // prodotti di terzi
    ];

    /** Caselle NO dei consensi: nessuna regola. */
    private const CASELLE_NO = ['Button5', 'Button6', 'Button8', 'Button11', 'Button13'];

    /** Corrispondenza delle sedi con i dati del cliente: persone fisiche sugli indirizzi, società sulla sede. */
    private const SEDE_AL_CLIENTE = [
        'branch.address' => 'client.address.street',
        'branch.street_number' => 'client.address.number',
        'branch.zip_code' => 'client.address.zip_code',
        'branch.city' => 'client.address.city',
        'branch.province' => 'client.address.province',
        'branch.indirizzo_completo' => 'client.address.full',
    ];

    public function run(): void
    {
        $module = PdfModule::query()->where('name', self::MODULO)->firstOrFail();

        foreach (self::TESTI as $field => [$key, $formatter, $required]) {
            $module->pdfModuleFields()->where('pdf_field_name', $field)->update([
                'source_key' => $key,
                'formatter' => $formatter,
                'is_required' => $required,
            ]);
        }

        foreach (self::CONSENSI as $field => $key) {
            $module->pdfModuleFields()->where('pdf_field_name', $field)->update(self::SI + ['checkbox_when' => $key]);
        }

        $module->pdfModuleFields()->whereIn('pdf_field_name', self::CASELLE_NO)->update(['checkbox_when' => null, 'source_key' => null]);

        $this->moduliSullIndirizzoDelCliente();
    }

    /**
     * Negli altri moduli importati la residenza usava la sede (`branch.*`) e il luogo di firma la città della sede:
     * ora il luogo è la sede dell'agente e l'indirizzo quello del cliente (indirizzi per le persone fisiche).
     */
    private function moduliSullIndirizzoDelCliente(): void
    {
        $fields = PdfModuleField::query();

        (clone $fields)->whereRaw('lower(pdf_field_name) = ?', ['luogo'])->update(['source_key' => 'agent.branch_city']);

        foreach (self::SEDE_AL_CLIENTE as $old => $new) {
            (clone $fields)->where('source_key', $old)->update(['source_key' => $new]);
        }

        // «Indirizzo» in un solo campo: via e numero civico insieme.
        PdfModuleField::query()
            ->where('source_key', 'client.address.street')
            ->whereHas('pdfModule', fn ($query) => $query->where('name', 'QAV Persona fisica'))
            ->update(['source_key' => 'client.address.line']);
    }
}
