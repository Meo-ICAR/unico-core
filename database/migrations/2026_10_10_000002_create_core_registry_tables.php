<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Connessione del database core, configurabile in config/unico-core.php.
     */
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    /**
     * Anagrafiche: clienti, mandanti, fornitori.
     */
    public function up(): void
    {
        Schema::create('client_types', function (Blueprint $table) {
            $table->id()->comment('ID univoco tipo cliente');
            $table->string('name')->comment('Descrizione');
            $table->boolean('is_person')->default(false)->comment('Persona fisica (true) o giuridica (false)');
            $table->boolean('is_company')->default(false)->comment('Indica se è una società/azienda');
            $table->string('privacy_role')->nullable()->comment('Ruolo Privacy (es. Titolare Autonomo, Responsabile Esterno)');
            $table->text('purpose')->nullable()->comment('Finalità del trattamento');
            $table->text('data_subjects')->nullable()->comment('Categorie di Interessati');
            $table->text('data_categories')->nullable()->comment('Categorie di Dati Trattati');
            $table->text('retention_period')->nullable()->comment('Tempi di Conservazione (Data Retention)');
            $table->text('extra_eu_transfer')->nullable()->comment('Trasferimento Extra-UE');
            $table->text('security_measures')->nullable()->comment('Misure di Sicurezza');
            $table->text('privacy_data')->nullable()->comment('Altri Dati Privacy');
            $table->timestamp('created_at')->nullable()->comment('Data di creazione');
            $table->timestamp('updated_at')->nullable()->comment('Ultima modifica');
            $table->comment('Anagrafica dei tipi/ruoli privacy di cliente.');
        });

        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->string('name')->comment('Nome identificativo della sorgente lead');
            $table->enum('type', ['call_center', 'website', 'old_client', 'social_media', 'referral', 'other'])->default('other')->comment('Macro-tipologia di canale per raggruppamento e statistiche ROI');
            $table->text('description')->nullable()->comment('Note o dettagli specifici sulla sorgente');
            $table->string('utm_source')->nullable()->comment('Parametro UTM Source per lead da campagne web');
            $table->string('utm_campaign')->nullable()->comment('Parametro UTM Campaign per lead da campagne web');
            $table->boolean('is_active')->default(true)->comment('Indica se la sorgente è attualmente attiva e selezionabile');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->index(['type', 'is_active'], 'lead_sources_type_is_active_index');
            $table->comment('Canali di acquisizione dei clienti');
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('company_id')->nullable()->comment('Azienda (tenant) proprietaria del record');
            $table->boolean('is_person')->default(true)->comment('Persona fisica (true) o giuridica (false)');
            $table->string('name')->comment('Cognome (se persona fisica) o Ragione Sociale (se giuridica)');
            $table->string('first_name')->nullable()->comment('Nome persona fisica');
            $table->string('tax_code', 16)->nullable()->comment('Codice Fiscale o Partita IVA del cliente');
            $table->string('vat_number', 20)->nullable()->comment('Partita IVA');
            $table->string('email')->nullable()->comment('Email di contatto principale');
            $table->string('phone', 50)->nullable()->comment('Recapito telefonico');
            $table->string('iban', 34)->nullable()->comment('IBAN del cliente (in chiaro)');
            $table->foreignId('employer_id')->nullable()->comment('Datore di lavoro (self-reference su clients.id)');
            $table->string('website', 50)->nullable()->comment('Sito web del cliente');
            $table->boolean('is_pep')->default(false)->comment('Persona Politicamente Esposta');
            $table->foreignId('client_type_id')->nullable()->comment('Classificazione cliente');
            $table->boolean('is_sanctioned')->default(false)->comment('Presente in liste antiterrorismo/blacklists');
            $table->boolean('is_remote_interaction')->default(false)->comment('Operatività a distanza = Rischio più alto');
            $table->timestamp('general_consent_at')->nullable()->comment('Consenso generale al trattamento base');
            $table->timestamp('privacy_policy_read_at')->nullable()->comment('Data presa visione informativa Art.13');
            $table->timestamp('consent_special_categories_at')->nullable()->comment('Consenso dati sanitari/giudiziari per polizze/CQS');
            $table->timestamp('consent_sic_at')->nullable()->comment('Consenso interrogazione CRIF/CTC/Experian');
            $table->timestamp('consent_marketing_at')->nullable()->comment('Consenso comunicazioni commerciali e newsletter');
            $table->timestamp('consent_profiling_at')->nullable()->comment('Consenso profilazione abitudini di consumo/spesa');
            $table->string('status')->default('raccolta_dati')->comment('raccolta_dati, valutazione_aml, approvata, sos_inviata, chiusa');
            $table->boolean('is_company')->default(false)->comment('Consulente aziendale (esterno con ruoli in organigramma); la persona giuridica si indica con is_person = false');
            $table->boolean('is_lead')->default(false)->comment('True se è un lead non ancora convertito');
            $table->foreignId('leadsource_id')->nullable()->comment('ID del client che ha fornito il lead');
            $table->timestamp('acquired_at')->nullable()->comment('Data di acquisizione del contatto');
            $table->string('contoCOGE')->nullable()->comment('Conto COGE');
            $table->boolean('privacy_consent')->default(false)->comment('Consenso privacy del cliente');
            $table->boolean('is_client')->default(true)->comment('contraente contratto');
            $table->text('subfornitori')->nullable()->comment('Subfornitori da comunicare per gradimento');
            $table->boolean('is_requiredApprovation')->default(false)->comment('Da far approvare per gradimento');
            $table->boolean('is_approved')->default(true)->comment('Approvata per gradimento');
            $table->boolean('is_anonymous')->default(false)->comment('Cliente anonimo (non comunicabile)');
            $table->timestamp('blacklist_at')->nullable()->comment('Data inserimento in blacklist');
            $table->foreignId('blacklisted_by')->nullable()->comment('ID dell\'utente che ha inserito in blacklist (senza link esterni)');
            $table->decimal('salary', 10, 2)->nullable()->comment('Retribuzione annuale del cliente');
            $table->decimal('salary_quote', 10, 2)->nullable()->comment('Quota retribuzione per calcoli finanziari');
            $table->boolean('is_art108')->default(false)->comment('Esente art. 108 - ex art. 128-novies TUB');
            $table->boolean('is_consultant_gdpr')->default(false)->comment('Consulente ai fini GDPR');
            $table->string('privacy_contact_email')->nullable()->comment('Email contatto privacy');
            $table->string('dpo_email')->nullable()->comment('Email DPO');
            $table->boolean('is_iso27001_certified')->default(false)->comment('Certificazione ISO 27001');
            $table->boolean('is_dummy')->default(false)->comment('Record fittizio, usato per test o dimostrazioni');
            $table->json('employee_roles')->nullable()->comment('Ruoli (nomi EmployeeType) associati a questo client (consulente esterno o cliente finale), stessa struttura di employees.employee_roles');
            $table->foreignId('user_id')->nullable()->comment('ID dell\'utente collegato (account di login)');
            $table->date('birth_date')->nullable()->comment('Data di nascita');
            $table->string('birth_place', 100)->nullable()->comment('Luogo di nascita (comune e provincia)');
            $table->char('sex', 1)->nullable()->comment('M | F');
            $table->string('citizenship', 60)->nullable()->comment('Cittadinanza');
            $table->foreignId('legal_representative_id')->nullable()->comment('Legale rappresentante / amministratore (self-reference su clients.id)');
            $table->string('pec')->nullable()->comment('Indirizzo PEC (posta elettronica certificata) della societa');
            $table->string('ateco_code', 10)->nullable()->comment('Codice Ateco dell\'attivita prevalente della societa');
            $table->string('cciaa_registration', 60)->nullable()->comment('Iscrizione alla Camera di Commercio (CCIAA / numero REA)');
            $table->string('legal_form', 100)->nullable()->comment('Forma giuridica (registro imprese)');
            $table->string('sdi_code', 10)->nullable()->comment('Codice destinatario SDI');
            $table->string('activity_status', 30)->nullable()->comment('Stato attivita (es. ATTIVA)');
            $table->date('company_started_at')->nullable()->comment('Data inizio attivita');
            $table->decimal('share_capital', 16, 2)->nullable()->comment('Capitale sociale');
            $table->unsignedInteger('employees')->nullable()->comment('Numero dipendenti (ultimo dato disponibile)');
            $table->decimal('turnover', 16, 2)->nullable()->comment('Fatturato ultimo bilancio');
            $table->decimal('net_worth', 16, 2)->nullable()->comment('Patrimonio netto ultimo bilancio');
            $table->unsignedSmallInteger('balance_year')->nullable()->comment('Anno ultimo bilancio');
            $table->timestamp('registry_updated_at')->nullable()->comment('Ultimo aggiornamento dei dati dal registro imprese');
            $table->timestamp('created_at')->nullable()->comment('Data acquisizione cliente');
            $table->timestamp('updated_at')->nullable()->comment('Ultima modifica anagrafica');
            $table->index(['blacklist_at'], 'clients_blacklist_at_index');
            $table->index(['is_anonymous'], 'clients_is_anonymous_index');
            $table->index(['is_approved'], 'clients_is_approved_index');
            $table->comment('Clienti finali: persone fisiche e giuridiche, con dati KYC e consensi privacy');
        });

        Schema::create('client_relations', function (Blueprint $table) {
            $table->id()->comment('ID univoco relazione cliente');
            $table->foreignId('company_id')->comment('ID società persona giuridica');
            $table->foreignId('client_id')->comment('ID persona fisica cliente');
            $table->foreignId('client_type_id')->nullable()->comment('Tipo di cliente');
            $table->decimal('shares_percentage', 5, 2)->nullable()->comment('Percentuale quote possedute');
            $table->boolean('is_titolare')->default(false)->comment('Se è titolare/socio di maggioranza');
            $table->date('data_inizio_ruolo')->nullable()->comment('Data inizio ruolo');
            $table->date('data_fine_ruolo')->nullable()->comment('Data fine ruolo');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->comment('Legami tra anagrafiche clienti (familiari, soci, collegati)');
        });

        Schema::create('client_mandates', function (Blueprint $table) {
            $table->id()->comment('ID univoco mandato cliente');
            $table->foreignId('client_id')->comment('Riferimento al cliente coinvolto (mysql_proforma.clients.id)');
            $table->string('numero_mandato')->comment('Numero identificativo mandato');
            $table->date('data_firma_mandato')->comment('Innesca Instaurazione Rapporto AUI');
            $table->date('data_scadenza_mandato')->comment('Innesca Chiusura Rapporto AUI (se non erogato prima)');
            $table->decimal('importo_richiesto_mandato', 15, 2)->nullable()->comment('Importo massimo richiesto nel mandato');
            $table->string('scopo_finanziamento')->nullable()->comment('Scopo del finanziamento (es. Acquisto Prima Casa, Liquidità)');
            $table->date('data_consegna_trasparenza')->nullable()->comment('Deve essere <= data_firma');
            $table->enum('stato', ['attivo', 'concluso_con_successo', 'scaduto', 'revocato'])->default('attivo')->comment('Stato del mandato');
            $table->string('ruolo')->nullable()->comment('Ruolo del mandatario (es. delegato, tutore)');
            $table->string('name')->nullable()->comment('Descrizione');
            $table->text('notes')->nullable()->comment('Note specifiche sul ruolo per questa pratica (es. "Garante solo per quota 50%")');
            $table->text('purpose_of_relationship')->nullable()->comment('Es: Acquisto prima casa');
            $table->text('funds_origin')->nullable()->comment('Es: Risparmi, donazione, stipendio');
            $table->boolean('oam_delivered')->default(false)->comment('Foglio informativo consegnato a questo soggetto?');
            $table->enum('role_risk_level', ['basso', 'medio', 'alto'])->nullable()->comment('Livello rischio specifico ruolo nella pratica');
            $table->timestamp('deleted_at')->nullable()->comment('Data e ora di eliminazione logica (soft delete); nullo = record attivo');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->unique(['numero_mandato'], 'client_mandates_numero_mandato_unique');
            $table->comment('Mandati con cui una persona agisce per conto di un cliente');
        });

        Schema::create('fornitoriroles', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->string('name', 50)->comment('Nome del ruolo (es. Agente Senior, Segnalatore)');
            $table->string('code', 20)->nullable()->comment('Codice univoco del ruolo (es. AG_SENIOR, SEGNALATORE)');
            $table->integer('level')->nullable()->default(1)->comment('Livello gerarchico (es. 1 = base, 5 = manager). Utile per calcoli provvigionali a cascata.');
            $table->string('description')->nullable()->comment('Breve descrizione dei permessi o del ruolo nella rete');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->unique(['code'], 'code');
            $table->comment('Tabella dei ruoli e livelli della rete dei mediatori creditizi.');
        });

        Schema::create('clientis', function (Blueprint $table) {
            $table->id()->comment('ID univoco del cliente/banca');
            $table->string('name')->nullable()->comment('Ragione sociale');
            $table->string('nome')->nullable()->comment('Nome commerciale');
            $table->string('piva', 16)->nullable()->comment('Partita IVA');
            $table->date('stipulated_at')->nullable()->comment('Data stipula contratto convenzione');
            $table->string('abi', 30)->nullable()->comment('Abi per banche o numero RUI ISVASS');
            $table->string('abi_name')->nullable()->comment('Nome ufficiale banca');
            $table->date('dismissed_at')->nullable()->comment('Data cessazione rapporto convenzione');
            $table->string('type', 30)->nullable()->comment('Banca / Assicurazione / Utility');
            $table->string('oam', 30)->nullable()->comment('Codice di iscrizione OAM');
            $table->string('oam_name')->nullable()->comment('Denominazione OAM');
            $table->date('oam_at')->nullable()->comment('Data iscrizione OAM');
            $table->string('numero_iscrizione_rui', 50)->nullable()->comment('Numero iscrizione OAM');
            $table->string('ivass', 30)->nullable()->comment('Codice di iscrizione IVASS');
            $table->date('ivass_at')->nullable()->comment('Data iscrizione IVASS');
            $table->string('ivass_name')->nullable()->comment('Denominazione OAM');
            $table->enum('ivass_section', ['A', 'B', 'C', 'D', 'E'])->nullable()->comment('Sezione IVASS');
            $table->string('mandate_number', 100)->nullable()->comment('Numero di protocollo o identificativo del contratto di mandato');
            $table->date('start_date')->nullable()->comment('Data di decorrenza del mandato');
            $table->date('end_date')->nullable()->comment('Data di scadenza (NULL se a tempo indeterminato)');
            $table->boolean('is_exclusive')->default(false)->comment('Indica se il mandato prevede l\'esclusiva per quella categoria');
            $table->enum('status', ['ATTIVO', 'SCADUTO', 'RECEDUTO', 'SOSPESO'])->default('ATTIVO')->comment('Stato operativo del mandato');
            $table->text('notes')->nullable()->comment('Note su provvigioni particolari o patti specifici');
            $table->enum('principal_type', ['--', 'banca', 'broker', 'captive', 'assicurazione'])->default('banca')->comment('Tipologia del mandante');
            $table->enum('submission_type', ['--', 'accesso portale', 'inoltro', 'entrambi'])->default('accesso portale')->comment('Modalità inoltro pratiche');
            $table->string('cf')->nullable()->comment('Codice fiscale');
            $table->string('website')->nullable()->comment('Sito web');
            $table->boolean('is_reported')->default(false)->comment('Accordi di segnalazione');
            $table->string('privacy_contact_email')->nullable()->comment('Email contatto privacy');
            $table->string('dpo_email')->nullable()->comment('Email DPO');
            $table->string('coge')->nullable()->comment('Codice contabile COGE');
            $table->string('codice')->nullable()->comment('Codice interno del cliente');
            $table->string('email')->nullable()->comment('Indirizzo email');
            $table->string('regione')->nullable()->comment('Regione');
            $table->string('citta')->nullable()->comment('Città');
            $table->foreignId('company_id')->nullable()->comment('ID dell\'azienda di riferimento');
            $table->foreignId('customertype_id')->nullable()->comment('Riferimento al tipo di cliente (chiave esterna)');
            $table->unsignedTinyInteger('is_active')->nullable()->default(1)->comment('Cliente attivo');
            $table->boolean('is_dummy')->default(false)->comment('Record fittizio, usato per test o dimostrazioni');
            $table->timestamp('deleted_at')->nullable()->comment('Timestamp di cancellazione (soft delete)');
            $table->timestamp('created_at')->nullable()->comment('Timestamp di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Timestamp di ultimo aggiornamento');
            $table->comment('Anagrafica Banche ed Istituti Finanziari (Clienti del mediatore)');
        });

        Schema::create('fornitoris', function (Blueprint $table) {
            $table->id()->comment('ID univoco del fornitore/agente');
            $table->string('name')->nullable()->comment('Ragione sociale');
            $table->string('nome')->nullable()->comment('Nome del referente');
            $table->date('stipulated_at')->nullable()->comment('Data stipula contratto collaborazione');
            $table->string('pec')->nullable()->comment('Indirizzo Posta Elettronica Certificata');
            $table->string('description')->nullable()->comment('Descrizione');
            $table->string('email_private')->nullable()->comment('Email riservata, non mostrata ai clienti');
            $table->enum('supervisor_type', ['no', 'si', 'filiale'])->default('no')->comment('Se supervisore indicare e specificare se di filiale');
            $table->string('oam', 30)->nullable()->comment('Oam');
            $table->date('oam_at')->nullable()->comment('Data iscrizione OAM');
            $table->string('oam_name')->nullable()->comment('Denominazione sociale registrata in OAM');
            $table->string('numero_iscrizione_rui', 50)->nullable()->comment('Numero iscrizione OAM');
            $table->string('ivass', 30)->nullable()->comment('Codice di iscrizione IVASS');
            $table->date('ivass_at')->nullable()->comment('Data iscrizione IVASS');
            $table->date('dismissed_at')->nullable()->comment('Data cessazione rapporto');
            $table->string('ivass_name')->nullable()->comment('Denominazione IVASS');
            $table->enum('ivass_section', ['A', 'B', 'C', 'D', 'E'])->nullable()->comment('Sezione IVASS');
            $table->string('type', 30)->nullable()->comment('Agente / Mediatore / Consulente / Call Center');
            $table->boolean('is_active')->default(true)->comment('Indica se agente è attualmente convenzionato');
            $table->boolean('is_art108')->default(false)->comment('Esente art. 108 - ex art. 128-novies TUB');
            $table->unsignedInteger('coordinated_type')->nullable()->comment('ID del dipendente coordinatore');
            $table->unsignedInteger('coordinated_id')->nullable()->comment('ID dell\'agente coordinatore');
            $table->foreignId('user_id')->nullable()->comment('ID dell\'utente collegato');
            $table->date('oam_dismissed_at')->nullable()->comment('Data revoca OAM');
            $table->decimal('welcome_bonus', 10, 2)->nullable()->comment('Premio benvenuto');
            $table->string('campagna')->nullable()->comment('Codice campagna');
            $table->date('available_at')->nullable()->comment('Data disponibilità agente');
            $table->decimal('budget', 10, 2)->nullable()->comment('Budget agente');
            $table->string('codice')->nullable()->comment('Codice interno del fornitore');
            $table->string('coge')->nullable()->comment('Codice contabile (COGE)');
            $table->date('natoil')->nullable()->comment('Data di nascita');
            $table->string('indirizzo')->nullable()->comment('Indirizzo');
            $table->string('comune')->nullable()->comment('Comune di residenza');
            $table->string('cap')->nullable()->comment('Codice di avviamento postale');
            $table->string('prov')->nullable()->comment('Provincia');
            $table->string('tel')->nullable()->comment('Numero di telefono');
            $table->string('coordinatore')->nullable()->comment('Nome del coordinatore di riferimento');
            $table->string('piva', 20)->nullable()->comment('Partita IVA');
            $table->char('cf', 16)->nullable()->comment('Codice fiscale');
            $table->string('nomecoge')->nullable()->comment('Nome per la contabilità');
            $table->string('nomefattura')->nullable()->comment('Nome da utilizzare in fattura');
            $table->string('email')->nullable()->comment('Indirizzo email');
            $table->decimal('anticipo', 15, 2)->nullable()->comment('Quota fissa mensile erogata come anticipo provvigionale');
            $table->enum('enasarco', ['no', 'monomandatario', 'plurimandatario', 'societa'])->nullable()->default('plurimandatario')->comment('Tipo di mandato ENASARCO');
            $table->decimal('anticipo_residuo', 15, 2)->nullable()->comment('Debito residuo dell\'agente verso il mediatore per anticipi da recuperare');
            $table->decimal('contributo', 15, 2)->nullable()->comment('Importo del contributo spese');
            $table->string('contributo_description')->nullable()->default('Contributo spese')->comment('Descrizione del contributo spese');
            $table->string('anticipo_description')->nullable()->default('Anticipo attuale')->comment('Descrizione dell\'anticipo');
            $table->tinyInteger('issubfornitore')->nullable()->comment('1 se l\'agente opera tramite un altro intermediario');
            $table->string('operatore')->nullable()->comment('Operatore interno di riferimento');
            $table->boolean('iscollaboratore')->nullable()->comment('Indica se è un collaboratore (1) o meno (0)');
            $table->boolean('isdipendente')->nullable()->default(false)->comment('Indica se è un dipendente (1) o meno (0)');
            $table->string('regione')->nullable()->comment('Regione');
            $table->string('citta')->nullable()->comment('Città');
            $table->foreignId('company_id')->nullable()->comment('Azienda (tenant) proprietaria del record');
            $table->smallInteger('contributoperiodicita')->nullable()->comment('Frequenza addebito costi (1=Mensile, 3=Trimestrale, etc.)');
            $table->date('contributodalmese')->nullable()->comment('Mese dal quale è dovuto il contributo');
            $table->foreignId('fornitorirole_id')->nullable()->comment('Ruolo del fornitore (banca, agente, ...)');
            $table->foreignId('branch_id')->nullable()->comment('Filiale o sede di riferimento');
            $table->json('employee_roles')->nullable()->comment('Ruoli (nomi EmployeeType) associati a questo fornitore, stessa struttura di employees.employee_roles');
            $table->timestamp('deleted_at')->nullable()->comment('Timestamp di cancellazione (soft delete)');
            $table->timestamp('created_at')->nullable()->comment('Timestamp di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Timestamp di ultimo aggiornamento');
            $table->index(['piva'], 'piva');
            $table->comment('Anagrafica Agenti di commercio / Collaboratori (Fornitori di servizi)');
        });

        Schema::create('oam_codes', function (Blueprint $table) {
            $table->id()->comment('ID autoincrementante');
            $table->string('code')->nullable()->comment('Codice ambito OAM');
            $table->string('name')->nullable()->comment('Descrizione ambito operativo');
            $table->string('description')->nullable()->comment('Codice e Descrizione ambito operativo');
            $table->string('tipo_prodotto')->nullable()->comment('Tipo finanziamento');
            $table->boolean('is_dummy')->default(false)->comment('Flag per identificare record fittizio per associare prodotti non presenti in OAM');
            $table->boolean('is_active')->default(true)->comment('Flag per identificare record attivo');
            $table->string('submission_type')->nullable()->comment('Tipo di invio previsto dalla segnalazione OAM');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->comment('Tabella di lookup globale (Senza Tenant): Ambiti operativi OAM.');
        });

        Schema::create('clienti_oam', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->string('clienti_id', 36)->nullable()->comment('mandataria');
            $table->foreignId('oam_code_id')->nullable()->comment('codice OAM');
            $table->date('dal')->nullable()->comment('data inizio convenzione');
            $table->date('al')->nullable()->comment('data fine convenzione');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->unique(['clienti_id', 'oam_code_id'], 'clienti_oam_clienti_id_oam_code_id_unique');
            $table->comment('Collegamento tra clienti e codici OAM');
        });

        Schema::create('blacklist_clienti_fornitori', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->string('cliente_id', 36)->comment('L\'istituto di credito (DB Esterno)');
            $table->string('fornitore_id', 36)->comment('L\'agente bloccato');
            $table->text('motivo')->nullable()->comment('Motivazione del blocco da parte della banca');
            $table->date('data_inizio')->nullable()->comment('Data di inizio blocco');
            $table->date('data_fine')->nullable()->comment('Eventuale data di fine blocco (se temporaneo)');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->unique(['cliente_id', 'fornitore_id'], 'blacklist_clienti_fornitori_cliente_id_fornitore_id_unique');
            $table->comment('Divieti di contatto tra un cliente e un fornitore');
        });

        Schema::create('blacklist_clienti_employees', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('cliente_id')->comment('Cliente in blacklist');
            $table->foreignId('employee_id')->comment('Dipendente a cui è vietato il contatto con il cliente');
            $table->text('motivo')->nullable()->comment('Motivo del divieto');
            $table->date('data_inizio')->nullable()->comment('Inizio del divieto');
            $table->date('data_fine')->nullable()->comment('Fine del divieto; nulla = a tempo indeterminato');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->index(['employee_id', 'cliente_id'], 'blacklist_clienti_employees_employee_id_cliente_id_index');
            $table->comment('Divieti di contatto tra un cliente e un dipendente');
        });

        Schema::create('onorabilita', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->string('nominativo')->comment('Nominativo della persona verificata');
            $table->date('data_ultimo_carico_pendente')->nullable()->comment('Data dell\'ultimo certificato dei carichi pendenti');
            $table->date('data_ultima_onorabilita')->nullable()->comment('Data dell\'ultima verifica dei requisiti di onorabilità');
            $table->comment('Verifiche dei requisiti di onorabilità delle persone che operano per l\'azienda');
        });

        Schema::create('address_types', function (Blueprint $table) {
            $table->id()->comment('ID univoco tipo indirizzo');
            $table->string('name')->nullable()->comment('Descrizione');
            $table->comment('Tipologie di indirizzo (sede legale, residenza, domicilio, ...)');
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->string('addressable_type')->comment('Classe del Modello collegato (es. App\\\\Models\\\\Client)');
            $table->unsignedBigInteger('addressable_id')->comment('ID del Modello (VARCHAR 36 per supportare sia UUID che Integer)');
            $table->index(['addressable_type', 'addressable_id']);
            $table->string('name')->nullable()->comment('Descrizione');
            $table->string('numero')->nullable()->comment('Numero civico o identificativo indirizzo');
            $table->string('street')->nullable()->comment('Via e numero civico');
            $table->string('city')->nullable()->comment('Città o Comune');
            $table->string('zip_code', 20)->nullable()->comment('CAP (Codice di Avviamento Postale)');
            $table->foreignId('address_type_id')->nullable()->comment('Relazione con tipologia indirizzo');
            $table->timestamp('created_at')->nullable()->comment('Data inserimento indirizzo');
            $table->timestamp('updated_at')->nullable()->comment('Data ultimo aggiornamento');
            $table->comment('Indirizzi di qualsiasi anagrafica (morph), con tipo');
        });

        Schema::create('organizations', function (Blueprint $table) {
            $table->id()->comment('Identificativo univoco del record');
            $table->string('acronym', 50)->comment('Acronimo o sigla identificativa dell\'ente (es. OAM, IVASS, UIF)');
            $table->string('name')->comment('Nome esteso e completo dell\'organismo di vigilanza');
            $table->text('description')->nullable()->comment('Dettagli aggiuntivi sui poteri, competenze o ambito di vigilanza dell\'ente');
            $table->string('reference_law')->nullable()->comment('Riferimento normativo istitutivo o principale (es. D.Lgs. 141/2010, TUB)');
            $table->string('website')->nullable()->comment('Indirizzo del sito web ufficiale dell\'ente');
            $table->string('pec_email')->nullable()->comment('Indirizzo PEC ufficiale dell\'ente per l\'invio di comunicazioni formali di compliance');
            $table->boolean('is_active')->default(true)->comment('Flag di stato: 1 = Attivo e vigilante, 0 = Storico/Disattivato');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->timestamp('deleted_at')->nullable()->comment('Timestamp di cancellazione logica (Soft Delete) per preservare lo storico degli audit di compliance');
            $table->unique(['acronym'], 'organizations_acronym_unique');
            $table->comment('Organismi di vigilanza e controllo a cui è sottoposto il mediatore creditizio (es. OAM, Banca d\'Italia).');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('address_types');
        Schema::dropIfExists('onorabilita');
        Schema::dropIfExists('blacklist_clienti_employees');
        Schema::dropIfExists('blacklist_clienti_fornitori');
        Schema::dropIfExists('clienti_oam');
        Schema::dropIfExists('oam_codes');
        Schema::dropIfExists('fornitoris');
        Schema::dropIfExists('clientis');
        Schema::dropIfExists('fornitoriroles');
        Schema::dropIfExists('client_mandates');
        Schema::dropIfExists('client_relations');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('lead_sources');
        Schema::dropIfExists('client_types');
    }
};
