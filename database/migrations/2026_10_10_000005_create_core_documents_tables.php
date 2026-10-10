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
     * Documenti, media, comunicazioni.
     */
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->string('name')->nullable()->comment('Nome documento');
            $table->text('description')->nullable()->comment('Descrizione aggiuntiva');
            $table->string('code')->nullable()->comment('Codice univoco mnemonico');
            $table->string('codegroup')->nullable()->comment('Raggruppa documenti simili');
            $table->string('slug')->nullable()->comment('Slug univoco per URL');
            $table->string('regex_pattern')->nullable()->comment('Pattern di validazione');
            $table->integer('priority')->default(0)->comment('Priorità di ordinamento');
            $table->string('phase')->nullable()->comment('Fase di processo');
            $table->boolean('is_person')->default(true)->comment('Documento inerente Persona');
            $table->boolean('is_company')->default(false)->comment('Documento inerente Azienda');
            $table->boolean('is_employee')->default(false)->comment('Richiesto ai dipendenti');
            $table->boolean('is_agent')->default(false)->comment('Richiesto agli agenti');
            $table->boolean('is_principal')->default(false)->comment('Richiesto alle mandanti');
            $table->boolean('is_client')->default(false)->comment('Richiesto ai clienti');
            $table->boolean('is_practice')->default(false)->comment('Legato a una pratica');
            $table->string('trigger_field')->nullable()->comment('Campo del modello da controllare');
            $table->boolean('is_signed')->default(false)->comment('Deve essere firmato');
            $table->boolean('is_monitored')->default(false)->comment('Scadenza monitorata nel tempo');
            $table->foreignId('renewed_by_id')->nullable()->comment('ID del documento che lo rinnova');
            $table->string('document_url')->nullable()->comment('URL pubblico o percorso del documento sul web/storage');
            $table->integer('training_hours')->nullable()->comment('Ore di formazione richieste');
            $table->enum('training_organization', ['interna', 'OAM', 'IVASS', 'PRIVACY'])->nullable()->comment('Formazione per organizzazione');
            $table->integer('duration')->nullable()->comment('Valore della validità');
            $table->string('duration_unit')->default('days')->comment('Unità di misura: hours, days, months, years');
            $table->string('nature')->nullable()->default('incoming')->comment('Tipo flusso: incoming, template_fillable, compliance');
            $table->enum('doctype', ['modulo', 'informativa', 'procedura', 'template'])->nullable()->comment('Tipo documento: modulo, procedura, template');
            $table->string('cellposition')->nullable()->comment('Posizione della cella in cui si trova il documento');
            $table->string('emitted_by')->nullable()->comment('Ente di rilascio predefinito');
            $table->boolean('is_sensible')->default(false)->comment('Contiene dati sensibili');
            $table->boolean('is_template')->default(false)->comment('Forniamo noi il template');
            $table->boolean('is_stored')->default(false)->comment('Richiede conservazione sostitutiva');
            $table->string('regex')->nullable()->comment('Pattern regex per classificazione');
            $table->boolean('is_end_month')->default(false)->comment('Approssima data a fine mese');
            $table->boolean('is_ai_abstract')->default(false)->comment('Ask AI to make abstract');
            $table->boolean('is_ai_check')->default(false)->comment('AI conformity required');
            $table->text('ai_pattern')->nullable()->comment('How AI can detect document is of this type');
            $table->unsignedTinyInteger('min_confidence')->default(70)->comment('Soglia minima per suggerire il tipo');
            $table->boolean('allow_auto_verification')->default(false)->comment('Valida da solo se confidence alta');
            $table->json('notify_days_before')->nullable()->comment('Es. [30, 15, 5] giorni prima');
            $table->unsignedTinyInteger('retention_years')->nullable()->comment('GDPR retention policy (anni)');
            $table->foreignId('created_by')->nullable()->comment('Utente che ha creato il record');
            $table->foreignId('updated_by')->nullable()->comment('Utente che ha modificato per ultimo il record');
            $table->foreignId('deleted_by')->nullable()->comment('Utente che ha eliminato il record');
            $table->boolean('is_versioned')->nullable()->default(false)->comment('Mantieni lo storico');
            $table->string('document_typable')->nullable()->comment('Alias del modello polimorfo a cui si applica il documento');
            $table->string('trigger_state')->nullable()->comment('filled, empty, equals');
            $table->string('trigger_value')->nullable()->comment('Il valore specifico da controllare');
            $table->string('exclude_field')->nullable()->comment('Campo del modello da escludere se valorizzato');
            $table->string('exclude_state')->nullable()->comment('filled, empty, equals');
            $table->string('exclude_value')->nullable()->comment('Il valore specifico da controllare');
            $table->foreignId('company_id')->nullable()->comment('NULL = tipo globale; valorizzato = tipo specifico del tenant');
            $table->string('ai_kind', 30)->nullable()->comment('Profilo di lettura AI (identita, codice_fiscale, reddito, informativa...)');
            $table->json('checks')->nullable()->comment('Controlli automatici da eseguire, per codice (es. tipo_documento, dati_coerenti)');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->timestamp('deleted_at')->nullable()->comment('Data e ora di eliminazione logica (soft delete); nullo = record attivo');
            $table->unique(['code'], 'document_types_code_unique');
            $table->unique(['slug'], 'document_types_slug_unique');
            $table->comment('Catalogo dei tipi documento: applicabilità, scadenze, regole AI, firma, conservazione e template');
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id()->comment('UUID v4 del documento');
            $table->foreignId('company_id')->nullable()->comment('ID Tenant proprietario');
            $table->string('documentable_type')->comment('Alias del modello a cui è collegato il documento (morph map)');
            $table->string('documentable_id', 36)->comment('ID del record a cui è collegato il documento');
            $table->index(['documentable_type', 'documentable_id']);
            $table->foreignId('document_type_id')->nullable()->comment('Tipo di documento di riferimento');
            $table->string('name')->nullable()->comment('Titolo logico o nome file del documento');
            $table->integer('training_hours')->nullable()->comment('Ore di formazione richieste');
            $table->enum('training_organization', ['interna', 'OAM', 'IVASS', 'PRIVACY'])->nullable()->comment('Formazione per organizzazione');
            $table->string('docnumber')->nullable()->comment('Numero protocollo o identificativo del documento');
            $table->string('spatie_collection', 100)->default('default')->comment('Nome della collection per Spatie Media Library');
            $table->string('document_url', 2048)->nullable()->comment('URL pubblico o percorso del documento sul web/storage');
            $table->string('status', 50)->default('caricato')->comment('Valori di DocumentStatus: na, richiesto, caricato, approvato, respinto, provvisorio, scaduto, illegibile, revocato');
            $table->timestamp('last_sent_at')->nullable()->comment('Data e ora ultimo sollecito inviato');
            $table->unsignedInteger('reminders_count')->default(0)->comment('Numero totale di solleciti inviati');
            $table->string('sync_status', 50)->default('local')->comment('Stato sincronizzazione cloud: local, syncing, synced, failed');
            $table->string('source_app')->default('local')->comment('Applicazione di origine (es. local, sharepoint, google_drive)');
            $table->string('app_id')->nullable()->comment('ID univoco del file nel sistema di terze parti');
            $table->string('app_drive_id')->nullable()->comment('ID del Drive/Folder nel sistema di terze parti');
            $table->string('app_etag')->nullable()->comment('ETag per il controllo della versione in cloud');
            $table->longText('extracted_text')->nullable()->comment('Testo grezzo estratto tramite OCR');
            $table->json('metadata')->nullable()->comment('Dati chiave estratti in formato JSON');
            $table->text('ai_abstract')->nullable()->comment('Riassunto testuale generato dall\'Intelligenza Artificiale');
            $table->unsignedTinyInteger('ai_confidence_score')->nullable()->comment('Punteggio di affidabilita AI (0-100)');
            $table->boolean('is_monitored')->default(false)->comment('Scadenza monitorata nel tempo');
            $table->boolean('is_template')->default(false)->comment('Indica se è un modello vuoto fornito dall\'azienda');
            $table->enum('doctype', ['modulo', 'informativa', 'procedura', 'template'])->nullable()->comment('Tipo documento: modulo, procedura, template');
            $table->string('cellposition')->nullable()->comment('Posizione della cella in cui si trova il documento');
            $table->boolean('is_signed')->default(false)->comment('Indica se il documento è stato firmato');
            $table->boolean('is_unique')->default(false)->comment('Se true, è l\'unico documento ammesso in questa collection');
            $table->boolean('is_end_month')->default(false)->comment('Approssima la scadenza all\'ultimo giorno del mese');
            $table->string('emitted_by')->nullable()->comment('Ente di rilascio');
            $table->date('emitted_at')->nullable()->comment('Data di emissione del documento');
            $table->date('expires_at')->nullable()->comment('Data di scadenza del documento');
            $table->timestamp('delivered_at')->nullable()->comment('Data di consegna fisica o digitale');
            $table->timestamp('signed_at')->nullable()->comment('Data e ora della firma');
            $table->text('description')->nullable()->comment('Descrizione pubblica visibile all\'utente');
            $table->text('internal_notes')->nullable()->comment('Note interne visibili solo agli amministratori');
            $table->text('rejection_note')->nullable()->comment('Motivazione in caso di rifiuto del documento');
            $table->foreignId('user_id')->nullable()->comment('FK Utente o Cliente intestatario');
            $table->foreignId('renewed_by')->nullable()->comment('FK Utente che ha aggiornato il documento');
            $table->foreignId('replaced_by_id')->nullable()->comment('Documento che sostituisce questo (es. versione firmata)');
            $table->foreignId('uploaded_by')->nullable()->comment('FK Utente che ha eseguito l\'upload');
            $table->foreignId('verified_by')->nullable()->comment('FK Admin che ha verificato e approvato');
            $table->timestamp('verified_at')->nullable()->comment('Data e ora di verifica');
            $table->foreignId('created_by')->nullable()->comment('FK Utente creatore del record');
            $table->foreignId('updated_by')->nullable()->comment('FK Utente che ha aggiornato il record');
            $table->foreignId('deleted_by')->nullable()->comment('FK Utente che ha eliminato il record');
            $table->string('file_hash', 64)->nullable()->comment('Hash SHA-256 del file per prevenire duplicati esatti');
            $table->string('channel', 30)->nullable()->comment('Canale di arrivo: upload, email, whatsapp, drive, sharepoint, generated, signature');
            $table->string('channel_ref')->nullable()->comment('Riferimento nel canale (Message-Id, media id WhatsApp, id busta di firma)');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->timestamp('deleted_at')->nullable()->comment('Data e ora di eliminazione logica (soft delete); nullo = record attivo');
            $table->index(['expires_at'], 'doc_expires_at_index');
            $table->index(['status'], 'doc_status_index');
            $table->index(['file_hash']);
            $table->comment('Archivio centralizzato dei documenti, allegati e file con supporto OCR e AI');
        });

        Schema::create('document_requirements', function (Blueprint $table) {
            $table->id()->comment('ID univoco della regola di associazione');
            $table->string('tipoprodotto_id', 36)->nullable()->comment('Prodotto a cui si applica il vincolo (DB Esterno). Se NULL, si applica a tutti i prodotti');
            $table->string('tipoprodotto_sub_id', 36)->nullable()->comment('Sottoprodotto specifico (es. CQS Pensionati). Se NULL, si applica a tutto il macro-prodotto');
            $table->foreignId('document_type_id')->comment('Tipo di documento di riferimento');
            $table->boolean('is_required')->default(true)->comment('Flag: true se il requisito è obbligatorio per l\'avanzamento');
            $table->integer('sort_order')->default(0)->comment('Ordinamento di visualizzazione del requisito nella checklist del prodotto');
            $table->foreignId('task_id')->nullable()->comment('Task per cui il documento è richiesto');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->unique(['tipoprodotto_id', 'tipoprodotto_sub_id', 'document_type_id'], 'req_tf_unique');
            $table->comment('Quali tipi documento servono per un prodotto/sottoprodotto o per un task (NULL = vale per tutti)');
        });

        Schema::create('document_requests', function (Blueprint $table) {
            $table->id()->comment('ID univoco dell\'istanza del requisito sulla pratica');
            $table->string('requestable_type')->comment('Record per cui si chiede il documento (pratica, cliente, dipendente...)');
            $table->string('requestable_id', 36)->comment('Collegamento alla pratica');
            $table->index(['requestable_type', 'requestable_id']);
            $table->string('name', 150)->nullable()->comment('Nome/Tipo di documento richiesto (es. Cedolino, Certificato di servizio)');
            $table->text('description')->nullable()->comment('Istruzioni dettagliate o motivo della richiesta della banca');
            $table->string('requested_by_role', 50)->default('bank')->comment('Chi ha originato la richiesta: bank (banca), backoffice (interno), agent (agente)');
            $table->string('assigned_to_type', 50)->default('agent')->comment('A chi spetta l\'azione: agent (agente), client (cliente)');
            $table->string('status', 50)->default('da_richiedere')->comment('da_richiedere, richiesto, caricato, approvato, respinto, non_necessario');
            $table->boolean('share_with_client')->default(true)->comment('Se 1, la richiesta è visibile e richiedibile direttamente al cliente finale');
            $table->dateTime('requested_at')->nullable()->comment('Quando è stato richiesto');
            $table->date('due_date')->nullable()->comment('Scadenza consigliata per l\'invio prima della decadenza');
            $table->text('rejected_reason')->nullable()->comment('Motivo dell\'eventuale rifiuto del documento (se non idoneo)');
            $table->timestamp('agent_notified_at')->nullable()->comment('Data e ora in cui l\'agente è stato notificato');
            $table->timestamp('client_notified_at')->nullable()->comment('Data e ora in cui il cliente è stato notificato (es. via email/SMS)');
            $table->foreignId('document_type_id')->nullable()->comment('Tipo di documento di riferimento');
            $table->timestamp('completed_at')->nullable()->comment('Data e ora di approvazione o evasione del requisito');
            $table->text('notes')->nullable()->comment('Note operative o dettagli sulle problematiche del requisito');
            $table->unsignedSmallInteger('sort_order')->nullable()->default(0)->comment('Ordine di visualizzazione');
            $table->timestamp('received_at')->nullable()->comment('Data e ora di ricezione del documento');
            $table->timestamp('reviewed_at')->nullable()->comment('Data e ora della verifica');
            $table->foreignId('company_id')->nullable()->comment('Azienda (tenant) proprietaria del record');
            $table->foreignId('document_id')->nullable()->comment('Documento che evade la richiesta');
            $table->boolean('is_required')->default(true)->comment('Documento obbligatorio');
            $table->foreignId('created_by')->nullable()->comment('Utente che ha creato il record');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->comment('Richieste di documenti su una pratica o un\'anagrafica, con stato e documento che le evade');
        });

        Schema::create('document_analyses', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('document_id')->comment('Documento di riferimento');
            $table->string('kind', 30)->comment('classification, extraction, verification, abstract');
            $table->json('checks')->nullable()->comment('Controlli eseguiti');
            $table->json('result')->nullable()->comment('Esito strutturato: campi estratti, anomalie, annotazioni');
            $table->string('outcome', 20)->nullable()->comment('passed, failed, needs_review');
            $table->unsignedTinyInteger('confidence')->nullable()->comment('0-100');
            $table->enum('operator_type', ['human', 'procedural', 'ai'])->nullable()->comment('Chi ha prodotto l\'esito');
            $table->string('operator_label')->nullable()->comment('Es. regex, ai.anthropic, verifier.signature');
            $table->string('ai_model', 60)->nullable()->comment('Modello AI che ha eseguito l\'analisi');
            $table->unsignedInteger('ai_input_tokens')->nullable()->comment('Token di input consumati');
            $table->unsignedInteger('ai_output_tokens')->nullable()->comment('Token di output generati');
            $table->decimal('ai_cost', 10, 6)->nullable()->comment('Costo in USD');
            $table->text('error')->nullable()->comment('Errore riscontrato durante l\'analisi');
            $table->foreignId('created_by')->nullable()->comment('Utente che ha avviato l\'analisi');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->index(['document_id', 'kind']);
            $table->comment('Storico delle analisi automatiche e manuali di un documento, con costi AI');
        });

        Schema::create('signature_requests', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('document_id')->comment('documents.id');
            $table->string('provider', 30)->comment('Servizio di firma elettronica (es. Yousign)');
            $table->string('provider_ref')->nullable()->comment('ID della richiesta nel servizio di firma');
            $table->string('status', 20)->default('pending')->comment('Stato della richiesta di firma');
            $table->dateTime('sent_at')->nullable()->comment('Data e ora dell\'invio ai firmatari');
            $table->dateTime('signed_at')->nullable()->comment('Data e ora del completamento delle firme');
            $table->dateTime('expires_at')->nullable()->comment('Data e ora di scadenza della richiesta');
            $table->dateTime('last_synced_at')->nullable()->comment('Ultima sincronizzazione con il servizio di firma');
            $table->dateTime('last_event_at')->nullable()->comment('Data dell\'ultimo evento ricevuto dal servizio di firma');
            $table->string('failure_reason')->nullable()->comment('Motivo del rifiuto o del fallimento');
            $table->foreignId('requested_by')->nullable()->comment('Utente che ha richiesto la firma');
            $table->string('token', 64)->nullable()->comment('riservato alla firma a distanza via link (fase 2)');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->unique(['token'], 'signature_requests_token_unique');
            $table->index(['provider_ref'], 'signature_requests_provider_ref_index');
            $table->index(['status'], 'signature_requests_status_index');
            $table->comment('Richieste di firma di un Document presso un provider (busta di firma).');
        });

        Schema::create('signature_request_signers', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('signature_request_id')->comment('Richiesta di firma a cui appartiene il firmatario');
            $table->unsignedTinyInteger('position')->comment('Ordine di firma');
            $table->string('slot', 30)->comment('Riquadro di firma del modulo assegnato al firmatario');
            $table->string('role', 20)->comment('Ruolo del firmatario (es. cliente, agente)');
            $table->string('name')->comment('Nome completo del firmatario');
            $table->string('first_name')->comment('Nome del firmatario');
            $table->string('last_name')->comment('Cognome del firmatario');
            $table->string('tax_code', 32)->nullable()->comment('Codice fiscale del firmatario');
            $table->string('email')->nullable()->comment('Indirizzo email');
            $table->string('phone', 32)->nullable()->comment('Telefono del firmatario, usato per il codice OTP');
            $table->string('signer_type', 20)->nullable()->comment('client | agent | user | manual');
            $table->string('signer_ref', 64)->nullable()->comment('Riferimento interno del firmatario');
            $table->string('status', 20)->default('waiting')->comment('Stato della firma del singolo firmatario');
            $table->dateTime('signed_at')->nullable()->comment('Data e ora della firma del singolo firmatario');
            $table->string('provider_signer_ref')->nullable()->comment('ID del firmatario nel servizio di firma');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->unique(['signature_request_id', 'slot'], 'signature_request_signers_signature_request_id_slot_unique');
            $table->comment('Firmatari di una richiesta di firma.');
        });

        Schema::create('pdf_modules', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->string('name')->comment('Nome mostrato all\'operatore');
            $table->string('file_path')->comment('Percorso relativo al disco public');
            $table->foreignId('document_type_id')->nullable()->comment('Tipo di documento a cui corrisponde il modulo');
            $table->string('version')->nullable()->comment('Versione del modulo (es. 03_2026)');
            $table->json('tipi_prodotto')->nullable()->comment('Nomi tipo_prodotto pertinenti (come in pratiches.tipo_prodotto); null = tutti');
            $table->string('client_scope', 30)->default('entrambi')->comment('persona_fisica | persona_giuridica | entrambi');
            $table->boolean('is_active')->default(true)->comment('Modulo disponibile per la compilazione');
            $table->json('signature_slots')->nullable()->comment('Riquadri di firma del modulo (JSON)');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->unique(['file_path'], 'pdf_modules_file_path_unique');
            $table->comment('Moduli PDF compilabili (AcroForm) presenti in storage/app/public/module.');
        });

        Schema::create('pdf_module_fields', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('pdf_module_id')->comment('Modulo PDF a cui appartiene il campo');
            $table->string('pdf_field_name')->comment('Nome del campo nel modulo PDF (AcroForm)');
            $table->string('pdf_field_type', 20)->default('text')->comment('text | checkbox');
            $table->string('source_key')->nullable()->comment('Valore di ModuleSourceKey; null = campo non compilato');
            $table->string('formatter', 30)->nullable()->comment('Valore di ModuleFormatter');
            $table->string('checkbox_on_value', 50)->nullable()->comment('Valore dello stato "spuntato" (es. si, Yes)');
            $table->string('checkbox_when')->nullable()->comment('Chiave ModuleSourceKey, con ! iniziale per negare');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->unique(['pdf_module_id', 'pdf_field_name'], 'pdf_module_fields_pdf_module_id_pdf_field_name_unique');
            $table->comment('Mappatura campo AcroForm -> chiave dati (ModuleSourceKey) per ogni modulo PDF.');
        });

        Schema::create('document_reminders', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('document_id')->comment('Documento di riferimento');
            $table->unsignedSmallInteger('days_before')->comment('Giorni mancanti alla scadenza al momento dell\'invio');
            $table->string('recipient_email')->comment('Indirizzo a cui è stato inviato il sollecito');
            $table->string('status', 20)->default('sent')->comment('Esito dell\'invio (sent, failed)');
            $table->text('error_message')->nullable()->comment('Messaggio di errore se l\'invio è fallito');
            $table->timestamp('sent_at')->comment('Data e ora dell\'invio');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->unique(['document_id', 'days_before'], 'document_reminders_document_days_unique');
            $table->index(['sent_at'], 'document_reminders_sent_at_index');
            $table->comment('Solleciti di scadenza inviati per i documenti, uno per soglia di giorni');
        });

        Schema::create('document_schedules', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('document_id')->comment('Documento di riferimento');
            $table->string('documentable_type')->comment('Alias del modello proprietario del documento in scadenza');
            $table->string('documentable_id', 36)->comment('ID del proprietario del documento in scadenza');
            $table->index(['documentable_type', 'documentable_id']);
            $table->string('entity_name')->comment('Nome del proprietario del documento, copiato per le liste');
            $table->string('documentable_group_key')->comment('Chiave che raggruppa le scadenze dello stesso proprietario');
            $table->string('document_name')->comment('Nome del documento, copiato per le liste');
            $table->string('document_type_name')->comment('Nome del tipo di documento, copiato per le liste');
            $table->date('expires_at')->nullable()->comment('Data di scadenza del documento');
            $table->integer('days_until_expiry')->comment('Giorni mancanti alla scadenza al momento dell\'ultimo calcolo (negativo = già scaduto)');
            $table->string('status')->comment('Stato del documento al momento dell\'ultimo calcolo');
            $table->timestamp('last_sent_at')->nullable()->comment('Data ultimo sollecito inviato');
            $table->integer('reminders_count')->default(0)->comment('Solleciti già inviati per questa scadenza');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->index(['expires_at', 'status'], 'document_schedules_expires_at_status_index');
            $table->index(['documentable_group_key'], 'document_schedules_documentable_group_key_index');
            $table->comment('Scadenziario dei documenti: una riga per documento in scadenza, ricalcolata dal sistema');
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('parent_id')->nullable()->comment('Record padre nella gerarchia');
            $table->string('name')->comment('Nome');
            $table->text('description')->nullable()->comment('Descrizione');
            $table->string('app_identifier', 50)->nullable()->comment('Identificativo dell\'app Unico a cui è riservato il record; nullo = visibile a tutte');
            $table->string('taskable')->nullable()->comment('Alias del modello a cui si applica il task');
            $table->string('trigger_field')->nullable()->comment('Campo del modello da controllare');
            $table->string('trigger_state')->nullable()->comment('filled, empty, equals');
            $table->string('trigger_value')->nullable()->comment('Il valore specifico da controllare');
            $table->string('trigger_subfield')->nullable()->comment('Secondo campo del modello da controllare (es. stato_pratica), in aggiunta a trigger_field');
            $table->string('trigger_subvalue')->nullable()->comment('Valore che trigger_subfield deve avere (uguale a)');
            $table->string('exclude_field')->nullable()->comment('Campo del modello da escludere se valorizzato');
            $table->string('exclude_state')->nullable()->comment('filled, empty, equals');
            $table->string('exclude_value')->nullable()->comment('Il valore specifico da controllare');
            $table->boolean('is_active')->default(true)->comment('Se falso il record è disattivato e non è più selezionabile');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->unique(['name'], 'tasks_name_unique');
            $table->index(['app_identifier'], 'tasks_app_identifier_index');
            $table->comment('Attività dei processi, collegabili a qualsiasi modello e ai documenti richiesti');
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->string('model_type')->comment('Alias del modello a cui è allegato il file (morph map)');
            $table->unsignedBigInteger('model_id')->comment('ID del record a cui è allegato il file');
            $table->index(['model_type', 'model_id']);
            $table->char('uuid', 36)->nullable()->comment('UUID pubblico del file');
            $table->string('collection_name')->comment('Collection di Spatie Media Library');
            $table->string('name')->comment('Nome logico del file');
            $table->string('file_name')->comment('Nome del file su disco');
            $table->string('mime_type')->nullable()->comment('Tipo MIME del file');
            $table->string('disk')->comment('Disco su cui è salvato il file');
            $table->string('conversions_disk')->nullable()->comment('Disco delle conversioni (miniature e derivati)');
            $table->unsignedBigInteger('size')->comment('Dimensione del file in byte');
            $table->json('manipulations')->comment('Manipolazioni da applicare alle conversioni (JSON)');
            $table->json('custom_properties')->comment('Proprietà personalizzate del file (JSON)');
            $table->json('generated_conversions')->comment('Conversioni già generate (JSON)');
            $table->json('responsive_images')->comment('Immagini responsive generate (JSON)');
            $table->unsignedInteger('order_column')->nullable()->comment('Posizione del file nella collection');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->unique(['uuid'], 'media_uuid_unique');
            $table->index(['order_column'], 'media_order_column_index');
            $table->comment('Registro dei media caricati nel sistema');
        });

        Schema::create('email_templates', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->string('code')->comment('Codice univoco di email (es. AUDIT_REMOTE)');
            $table->string('emailable')->nullable()->comment('Alias del modello a cui si applica il modello di email');
            $table->string('email_field')->nullable()->comment('Campo del modello contenente l\'email del sender');
            $table->string('trigger_field')->nullable()->comment('Campo del modello da controllare');
            $table->string('trigger_state')->nullable()->comment('filled, empty, equals');
            $table->string('trigger_value')->nullable()->comment('Il valore specifico da controllare');
            $table->string('name')->comment('Nome descrittivo per gli utenti');
            $table->string('subject')->comment('Oggetto della mail');
            $table->longText('body')->comment('Corpo della mail (supporta HTML e placeholders)');
            $table->json('placeholders')->nullable()->comment('Elenco delle variabili utilizzabili (es. {agente_nome})');
            $table->boolean('is_active')->default(true)->comment('Stato del template');
            $table->string('app_identifier', 50)->nullable()->comment('Identificativo dell\'app Unico a cui è riservato il record; nullo = visibile a tutte');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->unique(['code'], 'email_templates_code_unique');
            $table->index(['app_identifier'], 'email_templates_app_identifier_index');
            $table->comment('Registro dei template email configurabili');
        });

        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id()->comment('ID univoco dell account');
            $table->string('name')->nullable()->comment('Nome descrittivo dell account (es. Amministrazione PEC)');
            $table->string('email_address')->comment('Indirizzo email completo');
            $table->string('email_reply_to')->nullable()->comment('Indirizzo email di reply-to');
            $table->boolean('is_pec')->default(false)->comment('Indica se l account è una PEC (1) o una mail standard (0)');
            $table->enum('protocol', ['pop3', 'imap'])->default('imap')->comment('Protocollo di lettura: pop3 o imap');
            $table->string('imap_host')->comment('Host del server di posta in arrivo (es. pop3.pec.it)');
            $table->integer('imap_port')->comment('Porta del server di posta in arrivo (es. 995)');
            $table->string('imap_username')->comment('Username per la posta in arrivo');
            $table->text('imap_password')->nullable()->comment('Password criptata per la posta in arrivo');
            $table->enum('imap_encryption', ['none', 'ssl', 'tls'])->default('ssl')->comment('Tipo di crittografia per la posta in arrivo');
            $table->string('smtp_host')->nullable()->comment('Host del server di posta in uscita (es. smtps.pec.it)');
            $table->integer('smtp_port')->nullable()->comment('Porta del server di posta in uscita (es. 465)');
            $table->string('smtp_username')->nullable()->comment('Username per la posta in uscita');
            $table->text('smtp_password')->nullable()->comment('Password criptata per la posta in uscita');
            $table->enum('smtp_encryption', ['none', 'ssl', 'tls'])->default('ssl')->comment('Tipo di crittografia per la posta in uscita');
            $table->boolean('is_active')->default(true)->comment('Indica se l account è attualmente attivo');
            $table->string('mailable_type')->nullable()->comment('Alias del modello proprietario della casella (morph map)');
            $table->unsignedBigInteger('mailable_id')->nullable()->comment('ID del proprietario della casella (utente, dipendente, company)');
            $table->index(['mailable_type', 'mailable_id']);
            $table->foreignId('company_id')->nullable()->comment('Tenant proprietario della casella');
            $table->enum('auth_type', ['password', 'oauth2'])->default('password')->comment('Metodo di autenticazione');
            $table->string('provider')->nullable()->comment('Provider OAuth (google, microsoft)');
            $table->text('access_token')->nullable()->comment('Access token OAuth2, cifrato');
            $table->text('refresh_token')->nullable()->comment('Refresh token OAuth2, cifrato');
            $table->timestamp('token_expires_at')->nullable()->comment('Scadenza dell\'access token');
            $table->timestamp('last_synced_at')->nullable()->comment('Ultima scansione IMAP completata');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->timestamp('deleted_at')->nullable()->comment('Data e ora di eliminazione logica (soft delete); nullo = record attivo');
            $table->unique(['email_address'], 'mail_accounts_email_address_unique');
            $table->comment('Caselle email e PEC: IMAP in ingresso e SMTP in uscita, con Basic Auth o OAuth 2.0');
        });

        Schema::create('incoming_emails', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('company_id')->comment('Tenant proprietario della casella');
            $table->foreignId('mail_account_id')->nullable()->comment('Casella di posta da cui è stata scaricata');
            $table->string('message_id')->comment('Message-Id RFC 5322, senza parentesi angolari');
            $table->string('in_reply_to')->nullable()->comment('Message-Id del messaggio a cui risponde');
            $table->text('references')->nullable()->comment('Catena References dell\'header, separata da spazi');
            $table->string('thread_id')->nullable()->comment('Conversazione: prima reference o message_id');
            $table->string('from_email')->comment('Indirizzo del mittente');
            $table->string('from_name')->nullable()->comment('Nome del mittente');
            $table->json('to')->nullable()->comment('Destinatari (JSON)');
            $table->json('cc')->nullable()->comment('Destinatari in copia (JSON)');
            $table->string('subject')->nullable()->comment('Oggetto');
            $table->longText('body_text')->nullable()->comment('Corpo in testo semplice');
            $table->longText('body_html')->nullable()->comment('Corpo in HTML');
            $table->timestamp('received_at')->comment('Data del messaggio dal server');
            $table->boolean('is_read')->default(false)->comment('Messaggio già letto in Unico');
            $table->string('classification', 50)->nullable()->comment('Esito del classificatore');
            $table->string('related_type')->nullable()->comment('Record generato dall\'email (reclamo, DSAR, pratica, processo)');
            $table->unsignedBigInteger('related_id')->nullable()->comment('ID del record collegato');
            $table->index(['related_type', 'related_id']);
            $table->string('drive_folder_id')->nullable()->comment('Cartella Drive in cui è archiviata');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->timestamp('deleted_at')->nullable()->comment('Data e ora di eliminazione logica (soft delete); nullo = record attivo');
            $table->unique(['company_id', 'message_id'], 'incoming_emails_company_message_unique');
            $table->index(['company_id', 'thread_id']);
            $table->index(['company_id', 'is_read']);
            $table->comment('Archivio delle email lette dalle caselle IMAP');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_emails');
        Schema::dropIfExists('mail_accounts');
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('media');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('document_schedules');
        Schema::dropIfExists('document_reminders');
        Schema::dropIfExists('pdf_module_fields');
        Schema::dropIfExists('pdf_modules');
        Schema::dropIfExists('signature_request_signers');
        Schema::dropIfExists('signature_requests');
        Schema::dropIfExists('document_analyses');
        Schema::dropIfExists('document_requests');
        Schema::dropIfExists('document_requirements');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_types');
    }
};
