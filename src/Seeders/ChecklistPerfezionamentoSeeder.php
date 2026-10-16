<?php

namespace Unico\Core\Seeders;

use Illuminate\Database\Seeder;
use Unico\Core\Models\Cliente;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\Pratica;
use Unico\Core\Models\Task;
use Unico\Core\Models\TipoProdotto;

/**
 * Check list documentali per fasi della pratica.
 *
 *  1. PERFEZIONARE: i documenti da chiedere al cliente (task «Check list …», per gruppo di tipologia lavorativa);
 *  2. FASCICOLO COMPLETO: la modulistica da stampare e far firmare con OTP (task «… · FASCICOLO COMPLETO»);
 *  3. INVIO IN ISTRUTTORIA: l'invio in banca.
 *
 * Prodotti: Cessione, Delega, Mutui, Prestito personale, Leasing, Mutuo chirografario e Microcredito. I documenti
 * multipli sono numerati (Busta paga 1, 2, 3; CU 1, 2) e condividono un `codegroup`: un file si assegna al primo numero libero.
 * Crea i tipi documento che mancano, con la regex sul nome file e il riconoscimento AI. Ripetibile.
 */
class ChecklistPerfezionamentoSeeder extends Seeder
{
    public const FASE_FIRMA = 'FASCICOLO COMPLETO';

    private const ID = "Carta d'Identità";

    private const CF = 'Codice fiscale / Tessera sanitaria';

    private const IBAN = 'IBAN (coordinate bancarie)';

    private const BUSTE = ['Busta paga 1', 'Busta paga 2', 'Busta paga 3'];

    private const CU2 = ['Certificazione Unica (CU) 1', 'Certificazione Unica (CU) 2'];

    private const CEDOLINI = ['Cedolino pensione 1', 'Cedolino pensione 2', 'Cedolino pensione 3'];

    private const CORPORATE = 'Races VERS. 06_2025 - Fascicolo completo Corporate compilabile';

    private const FASCICOLO_CQ = 'Races VERS. 03_2026 - Fascicolo completo retail CQ compilabile';

    private const FASCICOLO_MUTUI = 'Races VERS. 03_2026 - Fascicolo completo retail MUTUI compilabile';

    private const FASCICOLO_PRESTITI = 'Races VERS. 03_2026 - Fascicolo completo retail Prestiti Personali compilabile';

    private const PROFORMA = '20240403 - proforma fattura mutuo NO IVA';

    /**
     * Tipi documento nuovi: nome => [regex sul nome del file, codegroup dei documenti numerati].
     *
     * @var array<string, array{0: string, 1?: string}>
     */
    private const TIPI = [
        'Busta paga 1' => ['/busta|paga|cedolino.*stipendio/i', 'BUSTA_PAGA'],
        'Busta paga 2' => ['/busta|paga|cedolino.*stipendio/i', 'BUSTA_PAGA'],
        'Busta paga 3' => ['/busta|paga|cedolino.*stipendio/i', 'BUSTA_PAGA'],
        'Certificazione Unica (CU) 1' => ['/\bcu\b|certificazione.?unica|cud/i', 'CU'],
        'Certificazione Unica (CU) 2' => ['/\bcu\b|certificazione.?unica|cud/i', 'CU'],
        'Cedolino pensione 1' => ['/cedolino|pension/i', 'CEDOLINO_PENSIONE'],
        'Cedolino pensione 2' => ['/cedolino|pension/i', 'CEDOLINO_PENSIONE'],
        'Cedolino pensione 3' => ['/cedolino|pension/i', 'CEDOLINO_PENSIONE'],
        'IBAN (coordinate bancarie)' => ['/iban|coordinate/i'],
        'Estratto conto fondo pensione' => ['/fondo.?pension/i'],
        'Bilancio anno precedente depositato' => ['/bilancio/i', 'BILANCIO'],
        'Bilancio anno corrente depositato' => ['/bilancio/i', 'BILANCIO'],
        'Bilancio in corso provvisorio' => ['/provvisori|situazione/i'],
        'Ricevuta di invio telematico del bilancio' => ['/ricevuta.*(invio|telematic)|telematic/i'],
        'Verbale di approvazione del bilancio' => ['/verbale/i'],
        'Centrale Rischi ultimi 36 mesi' => ['/centrale.?rischi|\bcr\b/i'],
        'Prospetto degli affidamenti bancari' => ['/affidament/i'],
        'Elenco principali clienti e fornitori' => ['/clienti|fornitori/i'],
        'Preventivi di spesa per investimenti' => ['/preventiv/i'],
        'Modello DM10' => ['/dm.?10/i'],
        'Dichiarazione dei redditi (ditte individuali e società di persone)' => ['/dichiarazion.*redditi|redditi/i'],
        'Registro dei corrispettivi o delle fatture attive' => ['/corrispettiv|fatture.?attive|registro/i'],
        'Situazione contabile provvisoria' => ['/situazione.?contabile/i'],
        'Lista movimenti aggiornata' => ['/movimenti/i'],
        'Documentazione bancaria dei soci e dei garanti' => ['/soci|garant/i'],
        "Documentazione fotografica dell'attività" => ['/foto/i'],
        'Preventivi firmati' => ['/preventiv.*firmat|firmat.*preventiv/i'],
        'Secondo documento di riconoscimento (patente o passaporto)' => ['/patente|passaporto/i'],
        'Stato di famiglia' => ['/stato.?(di)?.?famiglia/i'],
        'Stato libero o certificato di matrimonio con annotazioni' => ['/stato.?libero|matrimonio/i'],
        'Documentazione separazione/divorzio con condizioni economiche' => ['/separazione|divorzio/i'],
        'Estratto contributivo' => ['/contributiv/i'],
        'Documentazione risparmi (polizze, libretti postali, titoli, buoni)' => ['/risparmi|libretti|polizz|titoli|buoni/i'],
        "Partita IVA e iscrizione all'albo professionale" => ['/partita.?iva|albo/i'],
        'Informativa Privacy Compass' => ['/compass/i'],
        'Centrale Rischi di società, soci e garanti' => ['/centrale.?rischi|\bcr\b/i'],
        'Dichiarazione dei redditi 1' => ['/dichiarazion.*redditi|redditi|unico/i', 'DICHIARAZIONE_REDDITI'],
        'Dichiarazione dei redditi 2' => ['/dichiarazion.*redditi|redditi|unico/i', 'DICHIARAZIONE_REDDITI'],
        'Estratto conto trimestrale 1' => ['/estratto.?conto/i', 'ESTRATTO_CONTO'],
        'Estratto conto trimestrale 2' => ['/estratto.?conto/i', 'ESTRATTO_CONTO'],
        'Preventivi dei lavori di ristrutturazione' => ['/preventiv|lavori|ristruttur/i'],
    ];

    /** Tipi rinominati: nome precedente => nome attuale (le istanze già in database restano collegate). */
    private const RINOMINATI = [
        'Modello OBISM' => 'Modello OBIS/M',
        'Contratto di locazione' => 'Contratto di locazione o di acquisto',
        'Bilancio 2024 depositato' => 'Bilancio anno precedente depositato',
        'Bilancio 2025 depositato' => 'Bilancio anno corrente depositato',
        'Bilancio provvisorio 2026' => 'Bilancio in corso provvisorio',
    ];

    /** Prodotti aziendali: i task di stato (Inserita, ...) ricalcano quelli del leasing, non quelli retail. */
    private const PRODOTTI_AZIENDALI = ['CHIROGRAFARIO', 'Microcredito'];

    private const STATI_AZIENDALI = ['Inserita', 'ACCETTATO PREVENTIVO', 'Richiesta Istruttoria'];

    /** Mutuo per ristrutturazione: stessi task del mutuo, più i preventivi dei lavori. */
    public const MUTUO_RISTRUTTURAZIONE = 'Mutuo ristrutturazione';

    /** Tipi esistenti a cui si aggiunge il riconoscimento (regex e AI). */
    private const RICONOSCIMENTO = [
        'Visura camerale' => '/visura/i',
        'Contratto di locazione o di acquisto' => '/locazione|acquisto|contratto/i',
        'Busta paga' => '/busta|paga|cedolino/i',
        'Certificazione Unica (CU)' => '/\bcu\b|certificazione.?unica|cud/i',
        'Cedolino Pensione' => '/cedolino|pension/i',
        'Modello OBIS/M' => '/obis/i',
        'F24 / Agenzia delle Entrate' => '/f24/i',
        'Estratto Conto Bancario' => '/estratto.?conto/i',
        'Modello 730 / Redditi' => '/730|redditi|unico/i',
        'Company profile / presentazione' => '/presentazione|profile/i',
        'QAV Persona fisica' => '/qav|intervista|adeguata/i',
        'Certificato di stipendio e quota cedibile' => '/certificato.*stipendio|stipendio/i',
        'Delega richiesta allegati statali' => '/delega/i',
        self::PROFORMA => '/proforma/i',
        self::FASCICOLO_CQ => '/fascicolo/i',
        self::FASCICOLO_MUTUI => '/fascicolo/i',
        self::FASCICOLO_PRESTITI => '/fascicolo/i',
        self::CORPORATE => '/fascicolo/i',
    ];

    /** Modulistica che si stampa compilata e si fa firmare. */
    private const DA_FIRMARE = [self::FASCICOLO_CQ, self::FASCICOLO_MUTUI, self::FASCICOLO_PRESTITI, self::CORPORATE, self::PROFORMA, 'QAV Persona fisica', 'Informativa Privacy Compass'];

    /** Documenti per tutte le banche, da stampare e firmare. */
    private const ALLEGATO_B_DELEGA = 'Allegato B (delega)';

    private const ALLEGATO_E_DELEGA = 'Allegato E (delega)';

    /** Allegato B della cessione: solo per queste banche (nome dell'istituto in clientis => nome del tipo documento). */
    private const ALLEGATO_B_CQS = [
        'IBL BANCA SPA' => 'Allegato B per CQS (IBL Banca)',
        'BANCA DEL FUCINO' => 'Allegato B per CQS (Banca del Fucino)',
    ];

    public function run(): void
    {
        $this->rinomina();
        $this->tipi();
        $this->banche();
        $this->togliDaiTaskDiStato();

        foreach ($this->checklist() as [$name, $prodotto, $gruppo, $documenti]) {
            $this->task($name, 'Check list documentale: documenti da chiedere al cliente.', $prodotto, $gruppo === null ? null : 'employment_document_group', $gruppo, $documenti, true);
        }

        foreach ($this->firma() as [$prodotto, $documenti]) {
            $this->task($prodotto.' · '.self::FASE_FIRMA, 'Modulistica da stampare e far firmare con OTP.', $prodotto, 'stato_pratica', self::FASE_FIRMA, $documenti, false);
        }

        $this->allineaStatiAziendali();
        $this->mutuoRistrutturazione();
    }

    private function rinomina(): void
    {
        foreach (self::RINOMINATI as $old => $new) {
            if (! DocumentType::query()->where('name', $new)->exists()) {
                DocumentType::query()->where('name', $old)->update(['name' => $new]);
            }
        }
    }

    /** I task di stato del chirografario e del microcredito (prodotti aziendali) copiano quelli del leasing: niente busta paga e CU né fascicolo retail. */
    private function allineaStatiAziendali(): void
    {
        foreach (self::PRODOTTI_AZIENDALI as $prodotto) {
            foreach (self::STATI_AZIENDALI as $stato) {
                $origine = Task::query()->where('name', "LEASING · {$stato}")->first();
                $destinazione = Task::query()->where('name', "{$prodotto} · {$stato}")->first();

                if ($origine === null || $destinazione === null) {
                    continue;
                }

                $this->copiaRequisiti($origine, $destinazione);
            }
        }
    }

    /**
     * Task del mutuo per ristrutturazione: eredi di quelli del mutuo (`inherits_task_id`), quindi ne ereditano i documenti; le check list
     * aggiungono solo i preventivi dei lavori.
     */
    private function mutuoRistrutturazione(): void
    {
        $preventivi = DocumentType::query()->where('name', 'Preventivi dei lavori di ristrutturazione')->value('id');
        $prodotto = TipoProdotto::query()->where('name', self::MUTUO_RISTRUTTURAZIONE)->value('id');

        Task::query()->where('taskable', 'pratica')->delProdotto('Mutuo')->get()->each(function (Task $origine) use ($preventivi, $prodotto): void {
            $name = str_replace(['Mutuo ·', 'Mutui ·'], [self::MUTUO_RISTRUTTURAZIONE.' ·', 'Mutui ristrutturazione ·'], $origine->name);

            $figlio = Task::query()->firstOrCreate(['name' => $name], [
                'description' => $origine->description,
                'taskable' => 'pratica',
                'trigger_field' => 'tipo_prodotto',
                'trigger_value' => self::MUTUO_RISTRUTTURAZIONE,
                'trigger_state' => $origine->trigger_state,
                'trigger_subfield' => $origine->trigger_subfield,
                'trigger_subvalue' => $origine->trigger_subvalue,
                'is_active' => true,
            ]);
            $figlio->forceFill(['inherits_task_id' => $origine->getKey(), 'tipoprodotto_id' => $prodotto])->save();

            // Eredita tutto dal mutuo: restano propri solo i documenti in più.
            $propri = $origine->trigger_subfield === 'employment_document_group' && $preventivi !== null ? [$preventivi] : [];
            DocumentRequirement::query()->where('task_id', $figlio->getKey())->whereNotIn('document_type_id', $propri)->delete();

            foreach ($propri as $tipo) {
                DocumentRequirement::query()->updateOrCreate(
                    ['task_id' => $figlio->getKey(), 'document_type_id' => $tipo],
                    ['is_required' => true, 'sort_order' => 100],
                );
            }
        });
    }

    /** I requisiti di $destinazione diventano quelli di $origine (stessi documenti, stesso ordine). */
    private function copiaRequisiti(Task $origine, Task $destinazione): void
    {
        $ids = [];

        foreach ($origine->documentRequirements as $requisito) {
            $ids[] = $requisito->document_type_id;
            DocumentRequirement::query()->updateOrCreate(
                ['task_id' => $destinazione->getKey(), 'document_type_id' => $requisito->document_type_id],
                ['is_required' => $requisito->is_required, 'sort_order' => $requisito->sort_order],
            );
        }

        DocumentRequirement::query()->where('task_id', $destinazione->getKey())->whereNotIn('document_type_id', $ids)->delete();
    }

    private function tipi(): void
    {
        foreach (self::TIPI as $name => $definition) {
            [$regex, $codegroup] = $definition + [1 => null];

            $type = DocumentType::query()->firstOrCreate(['name' => $name], [
                'is_practice' => true,
                'is_client' => true,
                'nature' => 'incoming',
            ]);
            $rigenera = str_starts_with($name, 'Bilancio ');
            $type->forceFill(['regex' => $rigenera ? $regex : ($type->regex ?: $regex), 'is_ai_check' => true, 'codegroup' => $codegroup ?? $type->codegroup])->save();
        }

        foreach (self::RICONOSCIMENTO as $name => $regex) {
            $type = DocumentType::query()->where('name', $name)->first();
            $type?->forceFill(['regex' => $type->regex ?: $regex, 'is_ai_check' => true])->save();
        }

        // La modulistica si stampa compilata e si carica firmata.
        DocumentType::query()->whereIn('name', self::DA_FIRMARE)->update(['is_signed' => true]);
    }

    /**
     * Documenti specifici di una banca (`document_types.clienti_id`): si stampano e firmano solo sulle pratiche di quell'istituto.
     * Informativa Compass; Allegato B per CQS di IBL e Banca del Fucino (per Capital Fin non c'è); allegati B ed E della delega.
     */
    private function banche(): void
    {
        $compass = Cliente::query()->firstOrCreate(['name' => 'COMPASS BANCA S.P.A.'], ['nome' => 'Compass Banca S.p.A.', 'is_active' => true]);

        DocumentType::query()->where('name', 'Informativa Privacy Compass')->update(['clienti_id' => $compass->getKey()]);

        // Le pratiche già inserite con Compass come banca, ma senza il collegamento all'istituto.
        Pratica::query()->whereNull('clienti_id')->where('denominazione_banca', 'like', '%compass%')->toBase()->update(['clienti_id' => $compass->getKey()]);

        foreach (self::ALLEGATO_B_CQS as $bank => $name) {
            $this->tipoFirmato($name, Cliente::query()->where('name', $bank)->value('id'));
        }

        $this->tipoFirmato(self::ALLEGATO_B_DELEGA, null);
        $this->tipoFirmato(self::ALLEGATO_E_DELEGA, null);
    }

    private function tipoFirmato(string $name, int|string|null $clientiId): void
    {
        $type = DocumentType::query()->firstOrCreate(['name' => $name], ['is_practice' => true, 'is_client' => true, 'nature' => 'incoming']);
        $type->forceFill(['is_signed' => true, 'clienti_id' => $clientiId ?? $type->clienti_id])->save();
    }

    /** Il proforma del mutuo è un modulo da stampare: esce dal task «PERFEZIONATA» ed entra nella fase di firma. */
    private function togliDaiTaskDiStato(): void
    {
        $proforma = DocumentType::query()->where('name', self::PROFORMA)->value('id');
        $task = Task::query()->where('name', 'Mutuo · PERFEZIONATA')->value('id');

        if ($proforma !== null && $task !== null) {
            DocumentRequirement::query()->where('task_id', $task)->where('document_type_id', $proforma)->delete();
        }
    }

    /**
     * Fase PERFEZIONARE: solo ciò che si chiede al cliente.
     *
     * @return list<array{0: string, 1: string, 2: ?string, 3: list<array{0: string, 1: bool}>}> nome, prodotto, gruppo, documenti [nome, obbligatorio]
     */
    private function checklist(): array
    {
        $base = [[self::ID, true], [self::CF, true]];
        $buste = fn (int $n): array => array_map(fn (string $name): array => [$name, true], array_slice(self::BUSTE, 0, $n));
        $cu2 = array_map(fn (string $name): array => [$name, true], self::CU2);
        $cedolini = array_map(fn (string $name): array => [$name, true], self::CEDOLINI);

        // Cessione e delega
        $cqPensionati = [...$base, [self::IBAN, true], ['Modello OBIS/M', false], ['Cedolino Pensione', false], ['Certificazione Unica (CU)', true]];
        $cqPrivati = [...$base, [self::IBAN, true], ...$buste(2), ['Certificazione Unica (CU)', true], ['Certificato di stipendio e quota cedibile', false], ['Delega richiesta allegati statali', false], ['Estratto conto fondo pensione', false]];
        $cqPubblici = [...$base, ...$buste(2), ['Certificazione Unica (CU)', true]];

        // Mutui
        $famiglia = [['Stato di famiglia', true], ['Stato libero o certificato di matrimonio con annotazioni', true], ['Documentazione separazione/divorzio con condizioni economiche', false]];
        $coda = [['Estratto Conto Bancario', true], ['Lista movimenti aggiornata', true], ['Documentazione risparmi (polizze, libretti postali, titoli, buoni)', false]];
        $mutuiDipendenti = [...$base, ...$famiglia, ...$cu2, ...$buste(3), ['Estratto contributivo', true], ...$coda];
        $mutuiPensionati = [...$base, ...$famiglia, ...$cu2, ...$cedolini, ['Modello OBIS/M', true], ...$coda];
        $mutuiAutonomi = [
            ...$base, ...$famiglia,
            ['Dichiarazione dei redditi 1', true], ['Dichiarazione dei redditi 2', true], ['F24 / Agenzia delle Entrate', true],
            ['Estratto contributivo', true], ['Visura camerale', true], ["Partita IVA e iscrizione all'albo professionale", false],
            ['Estratto conto trimestrale 1', true], ['Estratto conto trimestrale 2', true],
            ['Lista movimenti aggiornata', true], ['Documentazione risparmi (polizze, libretti postali, titoli, buoni)', false],
        ];

        // Prestito personale
        $prestitoDipendenti = [...$base, ...$buste(2)];
        $prestitoPensionati = [...$base, ['Cedolino pensione 1', true]];

        // Corporate
        $corporate = fn (string $affidamenti): array => [
            ['Visura camerale', true], [self::ID, true], [self::CF, true],
            ['Bilancio anno precedente depositato', true], ['Bilancio anno corrente depositato', true],
            ['Ricevuta di invio telematico del bilancio', true], ['Verbale di approvazione del bilancio', true],
            ['Bilancio in corso provvisorio', true], ['Centrale Rischi ultimi 36 mesi', true], [$affidamenti, true],
            ['Company profile / presentazione', true], ['Preventivi di spesa per investimenti', false],
            ['Elenco principali clienti e fornitori', true],
        ];

        return [
            ['Cessione · pensionati', 'Cessione', 'pensionati', $cqPensionati],
            ['Cessione · privati', 'Cessione', 'privati', $cqPrivati],
            ['Cessione · pubblici', 'Cessione', 'pubblici', $cqPubblici],
            ['Cessione · parapubblici', 'Cessione', 'parapubblici', $cqPubblici],
            ['Delega · privati', 'Delega', 'privati', $cqPrivati],
            ['Delega · pubblici', 'Delega', 'pubblici', $cqPubblici],
            ['Delega · parapubblici', 'Delega', 'parapubblici', $cqPubblici],
            ['Check list Mutui · privati', 'Mutuo', 'privati', $mutuiDipendenti],
            ['Check list Mutui · pubblici', 'Mutuo', 'pubblici', $mutuiDipendenti],
            ['Check list Mutui · parapubblici', 'Mutuo', 'parapubblici', $mutuiDipendenti],
            ['Check list Mutui · pensionati', 'Mutuo', 'pensionati', $mutuiPensionati],
            ['Check list Mutui · autonomi', 'Mutuo', 'autonomi', $mutuiAutonomi],
            ['Check list Prestito personale · pensionati', 'Prestito', 'pensionati', $prestitoPensionati],
            ['Check list Prestito personale · privati', 'Prestito', 'privati', $prestitoDipendenti],
            ['Check list Prestito personale · pubblici', 'Prestito', 'pubblici', $prestitoDipendenti],
            ['Check list Prestito personale · parapubblici', 'Prestito', 'parapubblici', $prestitoDipendenti],
            ['Check list Corporate · Leasing', 'LEASING', null, $corporate('Prospetto degli affidamenti bancari')],
            ['Check list Corporate · Mutuo chirografario', 'CHIROGRAFARIO', null, $corporate('Prospetto degli affidamenti bancari')],
            ['Check list Corporate · Microcredito', 'Microcredito', null, [
                ['Visura camerale', true], [self::ID, true], ['Secondo documento di riconoscimento (patente o passaporto)', true], [self::CF, true],
                ['Modello DM10', true], ['Centrale Rischi di società, soci e garanti', true],
                ['Bilancio anno precedente depositato', true], ['Bilancio anno corrente depositato', true], ['Ricevuta di invio telematico del bilancio', true],
                ['Situazione contabile provvisoria', true],
                ['Dichiarazione dei redditi (ditte individuali e società di persone)', false],
                ['Registro dei corrispettivi o delle fatture attive', true],
                ['Estratto Conto Bancario', true], ['Lista movimenti aggiornata', true],
                ['Documentazione bancaria dei soci e dei garanti', true],
                ['Contratto di locazione o di acquisto', true], ["Documentazione fotografica dell'attività", true],
                ['Preventivi firmati', true],
            ]],
        ];
    }

    /**
     * Fase FASCICOLO COMPLETO: modulistica da stampare e firmare.
     *
     * @return list<array{0: string, 1: list<array{0: string, 1: bool}>}>
     */
    private function firma(): array
    {
        return [
            ['Cessione', [[self::FASCICOLO_CQ, true], ...array_map(fn (string $name): array => [$name, true], array_values(self::ALLEGATO_B_CQS))]],
            ['Delega', [[self::FASCICOLO_CQ, true], [self::ALLEGATO_B_DELEGA, true], [self::ALLEGATO_E_DELEGA, false]]],
            ['Mutuo', [[self::FASCICOLO_MUTUI, true], [self::PROFORMA, true]]],
            ['Prestito', [['QAV Persona fisica', true], ['Informativa Privacy Compass', true], [self::FASCICOLO_PRESTITI, true]]],
            ['LEASING', [[self::CORPORATE, true]]],
            ['CHIROGRAFARIO', [[self::CORPORATE, true]]],
            ['Microcredito', [[self::CORPORATE, true]]],
        ];
    }

    /**
     * @param  list<array{0: string, 1: bool}>  $documenti
     * @param  bool  $sostituisci  i requisiti del task si riscrivono secondo la lista (la check list è la fonte)
     */
    private function task(string $name, string $description, string $prodotto, ?string $subfield, ?string $subvalue, array $documenti, bool $sostituisci): void
    {
        $task = Task::query()->firstOrCreate(['name' => $name], [
            'description' => $description,
            'taskable' => 'pratica',
            'trigger_field' => 'tipo_prodotto',
            'trigger_value' => $prodotto,
            'tipoprodotto_id' => TipoProdotto::query()->where('name', $prodotto)->value('id'),
            'trigger_subfield' => $subfield,
            'trigger_subvalue' => $subvalue,
            'is_active' => true,
        ]);

        $ids = [];

        foreach ($documenti as $order => [$typeName, $required]) {
            $type = DocumentType::query()->where('name', $typeName)->first();

            if ($type === null) {
                $this->command?->warn("Tipo documento mancante: {$typeName}");

                continue;
            }

            $ids[] = $type->getKey();
            DocumentRequirement::query()->updateOrCreate(
                ['task_id' => $task->getKey(), 'document_type_id' => $type->getKey()],
                ['is_required' => $required, 'sort_order' => $order + 1],
            );
        }

        if ($sostituisci) {
            DocumentRequirement::query()->where('task_id', $task->getKey())->whereNotIn('document_type_id', $ids)->delete();
        }
    }
}
