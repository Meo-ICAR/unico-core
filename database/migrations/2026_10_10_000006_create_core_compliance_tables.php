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
     * Audit, reclami, antiriciclaggio, formazione.
     */
    public function up(): void
    {
        Schema::create('remediations', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->enum('remediation_type', ['AML', 'Gestione Reclami', 'Monitoraggio Rete', 'Privacy', 'Trasparenza', 'Assetto Organizzativo'])->nullable()->comment('categorizzare il rimedio');
            $table->string('name')->comment('nome rimedio');
            $table->string('code')->nullable()->comment('codice rimedio');
            $table->text('description')->nullable()->comment('Descrizione del rimedio');
            $table->integer('timeframe_hours')->nullable()->comment('Tempo massimo per attuarlo, in ore');
            $table->string('timeframe_desc')->nullable()->comment('Tempo massimo per attuarlo, in forma descrittiva');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->timestamp('deleted_at')->nullable()->comment('Data e ora di eliminazione logica (soft delete); nullo = record attivo');
            $table->index(['remediation_type'], 'remediations_remediation_type_index');
            $table->comment('Registro dettaglio dei rimedi obbligatori per le anomalie rilevate');
        });

        Schema::create('quality_reviews', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('company_id')->nullable()->comment('Azienda (tenant) proprietaria del record');
            $table->string('name')->comment('Titolo della revisione di qualità');
            $table->foreignId('reviewer_user_id')->nullable()->comment('Utente che esegue la revisione');
            $table->date('period_from')->nullable()->comment('Inizio del periodo esaminato');
            $table->date('period_to')->nullable()->comment('Fine del periodo esaminato');
            $table->unsignedInteger('sample_size')->default(0)->comment('Numero di pratiche o documenti campionati');
            $table->json('filters')->nullable()->comment('Parametri usati per l\'estrazione, per tracciabilità');
            $table->text('notes')->nullable()->comment('Note libere');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->timestamp('deleted_at')->nullable()->comment('Data e ora di eliminazione logica (soft delete); nullo = record attivo');
            $table->comment('Sessioni di controllo qualità: campione casuale di pratiche da sottoporre al responsabile qualità.');
        });

        Schema::create('audits', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('company_id')->nullable()->comment('Azienda (tenant) proprietaria del record');
            $table->foreignId('quality_review_id')->nullable()->comment('Revisione di qualità di cui fa parte l\'audit');
            $table->text('name')->nullable()->comment('Nome audit');
            $table->string('auditable_type')->comment('Alias del modello controllato (morph map)');
            $table->string('auditable_id', 36)->comment('ID del soggetto controllato');
            $table->index(['auditable_type', 'auditable_id']);
            $table->string('auditor_name')->nullable()->comment('Nome dell\'ispettore o dell\'auditor esterno/interno');
            $table->foreignId('organization_id')->nullable()->comment('Organizzazione che esegue o riceve l\'audit');
            $table->date('scheduled_at')->nullable()->comment('Data pianificata');
            $table->date('executed_at')->nullable()->comment('Data di esecuzione effettiva');
            $table->string('status')->default('planned')->comment('Stato: planned, in_progress, completed, cancelled');
            $table->string('protocol_number')->nullable()->comment('Numero di protocollo ufficiale (es. OAM)');
            $table->string('origin_type')->default('internal')->comment('internal o external_incoming');
            $table->string('execution_method')->default('documentale')->comment('Metodo: documentale, in_loco, intervista');
            $table->text('scope')->nullable()->comment('Ambito oggettivo del controllo');
            $table->string('outcome')->nullable()->comment('Esito: Passato, Con Rilievi, Fallito');
            $table->enum('severity', ['Alto', 'Medio', 'Basso'])->nullable()->comment('Severita rilievo');
            $table->text('summary')->nullable()->comment('Sintesi dei risultati');
            $table->text('auditor_notes')->nullable()->comment('Note riservate dell\'auditor');
            $table->text('remediation_plan')->nullable()->comment('Piano di rimedio richiesto');
            $table->date('followup_date')->nullable()->comment('Data di verifica dei rimedi');
            $table->date('followup_checked')->nullable()->comment('Data in cui è stato verificato il seguito');
            $table->text('followup_notes')->nullable()->comment('Note sul seguito dell\'audit');
            $table->json('rilievi_codes')->nullable()->comment('Codici rilievi');
            $table->string('collaboratore')->nullable()->comment('Tipologia collaboratore');
            $table->string('drive_folder_id')->nullable()->comment('Cartella Google Drive delle evidenze dell\'audit');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->timestamp('deleted_at')->nullable()->comment('Data e ora di eliminazione logica (soft delete); nullo = record attivo');
            $table->unique(['protocol_number'], 'audits_protocol_number_unique');
            $table->index(['scheduled_at'], 'audits_scheduled_at_index');
            $table->index(['executed_at'], 'audits_executed_at_index');
            $table->index(['status'], 'audits_status_index');
            $table->index(['origin_type'], 'audits_origin_type_index');
            $table->index(['outcome'], 'audits_outcome_index');
            $table->comment('Registro dei controlli (audit) eseguiti sui collaboratori/impiegati, richiesti da enti interni o esterni.');
        });

        Schema::create('audit_findings', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('audit_id')->comment('Audit in cui è emersa la non conformità');
            $table->foreignId('company_id')->comment('Azienda (tenant) proprietaria del record');
            $table->string('title')->comment('Titolo sintetico della non conformità');
            $table->text('description')->comment('Descrizione della non conformità');
            $table->string('severity')->default('minor')->comment('Gravità della non conformità');
            $table->string('status')->default('open')->comment('Stato di avanzamento della gestione');
            $table->boolean('requires_investigation')->default(false)->comment('Richiede un approfondimento');
            $table->text('investigation_notes')->nullable()->comment('Esito dell\'approfondimento');
            $table->date('investigation_deadline')->nullable()->comment('Scadenza dell\'approfondimento');
            $table->boolean('requires_corrective_action')->default(true)->comment('Richiede un\'azione correttiva');
            $table->text('corrective_action_description')->nullable()->comment('Descrizione dell\'azione correttiva');
            $table->foreignId('remediation_id')->nullable()->comment('Rimedio standard applicato');
            $table->date('corrective_action_deadline')->nullable()->comment('Scadenza dell\'azione correttiva');
            $table->date('resolved_at')->nullable()->comment('Data di risoluzione');
            $table->text('resolution_notes')->nullable()->comment('Note sulla risoluzione');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->timestamp('deleted_at')->nullable()->comment('Data e ora di eliminazione logica (soft delete); nullo = record attivo');
            $table->index(['severity'], 'audit_findings_severity_index');
            $table->index(['status'], 'audit_findings_status_index');
            $table->comment('Registro dei rilievi (anomalie/non conformità) emersi durante gli audit');
        });

        Schema::create('audit_checklist_evaluations', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('audit_id')->comment('Audit di riferimento');
            $table->unsignedBigInteger('audit_checklist_item_id')->comment('Riferimento debole a audit_checklist_items (connessione di default)');
            $table->unsignedBigInteger('external_processor_id')->nullable()->comment('Riferimento debole a external_processors (connessione di default)');
            $table->text('internal_documentation')->nullable()->comment('Documentazione interna/policy disponibile (Titolare/Responsabile)');
            $table->text('vendor_evidence_notes')->nullable()->comment('Evidenza caso specifico / sub-fornitore');
            $table->string('gap_status')->default('da_verificare')->comment('Enum App\\\\Enums\\\\AuditChecklistGapStatus');
            $table->text('gap_notes')->nullable()->comment('Carenze riscontrate rispetto alla voce di checklist');
            $table->boolean('is_vendor_scope')->default(false)->comment('true se il gap è attribuibile al sub-fornitore/vendor');
            $table->boolean('is_optional')->default(false)->comment('Voce facoltativa: la sua mancanza non impedisce di chiudere l\'audit');
            $table->date('verified_at')->nullable()->comment('Data della verifica della voce');
            $table->date('next_review_at')->nullable()->comment('Data della prossima verifica');
            $table->foreignId('verified_by')->nullable()->comment('Utente che ha verificato la voce');
            $table->timestamp('deleted_at')->nullable()->comment('Data e ora di eliminazione logica (soft delete); nullo = record attivo');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->unique(['audit_id', 'audit_checklist_item_id'], 'audit_checklist_eval_unique');
            $table->comment('Valutazione delle voci della checklist durante un audit');
        });

        Schema::create('complaints', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('company_id')->comment('Logical FK: db_bpm.companies');
            $table->string('complaintable_type')->comment('Alias del modello oggetto del reclamo (morph map)');
            $table->unsignedBigInteger('complaintable_id')->comment('ID del record oggetto del reclamo');
            $table->index(['complaintable_type', 'complaintable_id']);
            $table->date('received_at')->comment('Data di ricezione del reclamo');
            $table->string('subject')->comment('Oggetto del reclamo');
            $table->text('description')->comment('Descrizione del reclamo');
            $table->string('status')->comment('Enum: ricevuto, in_lavorazione, accolto, respinto');
            $table->date('resolved_at')->nullable()->comment('Data di risoluzione del reclamo');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->timestamp('deleted_at')->nullable()->comment('Data e ora di eliminazione logica (soft delete); nullo = record attivo');
            $table->index(['status'], 'complaints_status_index');
            $table->index(['received_at'], 'complaints_received_at_index');
            $table->comment('Registro delle segnalazioni e reclami ricevuti');
        });

        Schema::create('complaint_registry', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('company_id')->comment('Logical FK: db_bpm.companies');
            $table->string('protocol_number', 50)->nullable()->comment('Protocollo interno univoco');
            $table->date('received_at')->nullable()->comment('Data ufficiale di ricezione');
            $table->string('reception_channel', 50)->nullable()->comment('Enum: pec, raccomandata, email, brevi_manu');
            $table->string('receiving_email')->nullable()->comment('La casella aziendale che ha ricevuto la notifica');
            $table->string('complainant_type')->nullable()->comment('Alias del modello di chi ha presentato il reclamo (morph map)');
            $table->unsignedBigInteger('complainant_id')->nullable()->comment('ID di chi ha presentato il reclamo');
            $table->index(['complainant_type', 'complainant_id']);
            $table->string('complainant_name')->nullable()->comment('Nome se il reclamante non è censito a DB');
            $table->string('complainant_email')->nullable()->comment('Contatto del reclamante');
            $table->string('macro_category', 30)->nullable()->comment('Enum: financial, privacy, insurance, operational');
            $table->string('category', 50)->nullable()->comment('Enum: delay, behavior, fraud, gdpr_access, gdpr_erasure');
            $table->string('subject_type')->nullable()->comment('Alias del modello oggetto del record (morph map)');
            $table->unsignedBigInteger('subject_id')->nullable()->comment('ID del record oggetto');
            $table->index(['subject_type', 'subject_id']);
            $table->foreignId('agent_id')->nullable()->comment('Collaboratore/Agente della rete coinvolto');
            $table->foreignId('bank_id')->nullable()->comment('Banca Mandante / Ente Erogante coinvolto');
            $table->text('description')->comment('Testo principale del reclamo');
            $table->decimal('financial_impact', 10, 2)->default(0.00)->comment('Eventuale richiesta danni o rimborso in EUR');
            $table->string('status', 30)->nullable()->default('open')->comment('Enum: open, investigating, accepted, rejected, escalated');
            $table->date('deadline_at')->nullable()->comment('Scadenza legale (es. 60gg per bancari, 30gg per GDPR)');
            $table->boolean('is_extended')->default(false)->comment('Se i termini sono stati estesi legalmente');
            $table->timestamp('resolved_at')->nullable()->comment('Data e ora di risoluzione');
            $table->text('resolution_notes')->nullable()->comment('Esito dell\'istruttoria e motivazioni');
            $table->string('escalated_to', 50)->nullable()->comment('Se rigettato, eventuale ricorso a: abf, oam, ivass, garante');
            $table->unsignedBigInteger('data_subject_request_id')->nullable()->comment('Richiesta dell\'interessato (DSAR) collegata');
            $table->unsignedInteger('event_sequence')->nullable()->comment('Numero progressivo dell\'evento nella cronologia');
            $table->dateTime('event_at')->nullable()->comment('Data e ora dell\'evento');
            $table->string('event_phase')->nullable()->comment('Fase del processo a cui appartiene l\'evento');
            $table->string('mandating_company')->nullable()->comment('Società mandante');
            $table->string('master_agency')->nullable()->comment('Agenzia capogruppo');
            $table->string('sub_supplier')->nullable()->comment('Subfornitore coinvolto');
            $table->string('caller_number')->nullable()->comment('Numero chiamante');
            $table->string('agcom_roc_compliance')->nullable()->comment('Esito del controllo con il Registro degli Operatori di Comunicazione AGCOM');
            $table->string('event_channel_label')->nullable()->comment('Canale dell\'evento (telefono, email, ...)');
            $table->string('event_direction')->nullable()->comment('Direzione dell\'evento: in entrata o in uscita');
            $table->string('event_counterparty')->nullable()->comment('Controparte dell\'evento');
            $table->string('complainant_phone')->nullable()->comment('Telefono di chi ha presentato il reclamo');
            $table->string('complainant_fiscal_code')->nullable()->comment('Codice fiscale di chi ha presentato il reclamo');
            $table->text('operational_action')->nullable()->comment('Azione operativa intrapresa');
            $table->string('dnc_blacklist_status')->nullable()->comment('Stato nel registro delle opposizioni e nella blacklist interna');
            $table->string('sla_deadline_note')->nullable()->comment('Note sulla scadenza dell\'SLA');
            $table->text('log_freeze_retention')->nullable()->comment('Note sul blocco dei log e sulla loro conservazione');
            $table->string('evidence_attachment')->nullable()->comment('Riferimento al file di prova');
            $table->string('phase_status')->nullable()->comment('Stato della fase');
            $table->string('assigned_to')->nullable()->comment('Responsabile della gestione');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->timestamp('deleted_at')->nullable()->comment('Data e ora di eliminazione logica (soft delete); nullo = record attivo');
            $table->unique(['protocol_number', 'event_sequence'], 'complaint_registry_protocol_number_event_sequence_unique');
            $table->index(['company_id', 'status'], 'complaint_registry_company_id_status_index');
            $table->index(['deadline_at'], 'complaint_registry_deadline_at_index');
            $table->index(['macro_category', 'category'], 'complaint_registry_macro_category_category_index');
            $table->index(['protocol_number'], 'complaint_registry_protocol_number_index');
            $table->index(['data_subject_request_id'], 'complaint_registry_data_subject_request_id_index');
            $table->comment('Registro ufficiale Reclami e Segnalazioni (OAM, IVASS, Privacy)');
        });

        Schema::create('suspicious_activity_reports', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('company_id')->comment('Logical FK: db_bpm.companies');
            $table->foreignId('client_id')->nullable()->comment('Cliente a cui si riferisce la segnalazione');
            $table->string('reportable_type')->comment('Alias del modello oggetto della segnalazione (morph map)');
            $table->unsignedBigInteger('reportable_id')->comment('ID del record oggetto della segnalazione');
            $table->index(['reportable_type', 'reportable_id']);
            $table->timestamp('reported_at')->nullable()->comment('Data e ora della segnalazione');
            $table->json('anomalies_codes')->nullable()->comment('Codici delle anomalie riscontrate (JSON)');
            $table->text('description')->comment('Descrizione dell\'operazione sospetta');
            $table->enum('status', ['pending', 'investigated', 'reported', 'archived'])->default('pending')->comment('Stato: in attesa, approfondita, segnalata all\'UIF, archiviata');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->timestamp('deleted_at')->nullable()->comment('Data e ora di eliminazione logica (soft delete); nullo = record attivo');
            $table->comment('Registro dettaglio delle segnalazioni di attività sospette');
        });

        Schema::create('training_records', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('company_id')->comment('Azienda (tenant) proprietaria del record');
            $table->string('trainable_type')->comment('Alias del modello di chi ha seguito il corso (morph map)');
            $table->unsignedBigInteger('trainable_id')->comment('ID di chi ha seguito il corso');
            $table->index(['trainable_type', 'trainable_id']);
            $table->enum('regulatory_framework', ['gdpr', 'oam', 'ivass', 'sicurezza_lavoro', 'antiriciclaggio', 'mifid', 'other'])->nullable()->comment('Normativa a cui si riferisce la formazione');
            $table->string('name')->nullable()->comment('Titolo del corso');
            $table->text('description')->nullable()->comment('Contenuti e obiettivi del corso');
            $table->string('provider')->nullable()->comment('Ente che eroga il corso');
            $table->string('trainer')->nullable()->comment('Docente o formatore');
            $table->enum('delivery_mode', ['in_person', 'online', 'blended', 'on_the_job', 'webinar'])->default('in_person')->comment('Modalità di erogazione del corso');
            $table->date('training_date')->nullable()->comment('Data di svolgimento');
            $table->date('expiry_date')->nullable()->comment('Data di scadenza della validità del corso');
            $table->decimal('hours', 5, 1)->default(0.0)->comment('Ore di formazione');
            $table->enum('outcome', ['passed', 'failed', 'attended', 'partial'])->default('attended')->comment('Esito del corso');
            $table->decimal('score', 5, 2)->nullable()->comment('Punteggio ottenuto');
            $table->boolean('certificate_issued')->default(false)->comment('Indica se è stato rilasciato un attestato');
            $table->string('certificate_number')->nullable()->comment('Numero dell\'attestato');
            $table->text('notes')->nullable()->comment('Note libere');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->timestamp('deleted_at')->nullable()->comment('Data e ora di eliminazione logica (soft delete); nullo = record attivo');
            $table->comment('Registro della formazione di dipendenti e collaboratori, con esito e attestato');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_records');
        Schema::dropIfExists('suspicious_activity_reports');
        Schema::dropIfExists('complaint_registry');
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('audit_checklist_evaluations');
        Schema::dropIfExists('audit_findings');
        Schema::dropIfExists('audits');
        Schema::dropIfExists('quality_reviews');
        Schema::dropIfExists('remediations');
    }
};
