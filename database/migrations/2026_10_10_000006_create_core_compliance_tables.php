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
            $table->id();
            $table->enum('remediation_type', ['AML', 'Gestione Reclami', 'Monitoraggio Rete', 'Privacy', 'Trasparenza', 'Assetto Organizzativo'])->nullable()->comment('categorizzare il rimedio');
            $table->string('name')->comment('nome rimedio');
            $table->string('code')->nullable()->comment('codice rimedio');
            $table->text('description')->nullable();
            $table->integer('timeframe_hours')->nullable();
            $table->string('timeframe_desc')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['remediation_type'], 'remediations_remediation_type_index');
            $table->comment('Registro dettaglio dei rimedi obbligatori per le anomalie rilevate');
        });

        Schema::create('quality_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable();
            $table->string('name');
            $table->foreignId('reviewer_user_id')->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->unsignedInteger('sample_size')->default(0);
            $table->json('filters')->nullable()->comment('Parametri usati per l\'estrazione, per tracciabilità');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->comment('Sessioni di controllo qualità: campione casuale di pratiche da sottoporre al responsabile qualità.');
        });

        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable();
            $table->foreignId('quality_review_id')->nullable();
            $table->text('name')->nullable()->comment('Nome audit');
            $table->morphs('auditable');
            $table->string('auditor_name')->nullable()->comment('Nome dell\'ispettore o dell\'auditor esterno/interno');
            $table->foreignId('organization_id')->nullable();
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
            $table->date('followup_checked')->nullable();
            $table->text('followup_notes')->nullable();
            $table->json('rilievi_codes')->nullable()->comment('Codici rilievi');
            $table->string('collaboratore')->nullable()->comment('Tipologia collaboratore');
            $table->string('drive_folder_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['protocol_number'], 'audits_protocol_number_unique');
            $table->index(['scheduled_at'], 'audits_scheduled_at_index');
            $table->index(['executed_at'], 'audits_executed_at_index');
            $table->index(['status'], 'audits_status_index');
            $table->index(['origin_type'], 'audits_origin_type_index');
            $table->index(['outcome'], 'audits_outcome_index');
            $table->comment('Registro dei controlli (audit) eseguiti sui collaboratori/impiegati, richiesti da enti interni o esterni.');
        });

        Schema::create('audit_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id');
            $table->foreignId('company_id');
            $table->string('title');
            $table->text('description');
            $table->string('severity')->default('minor');
            $table->string('status')->default('open');
            $table->boolean('requires_investigation')->default(false);
            $table->text('investigation_notes')->nullable();
            $table->date('investigation_deadline')->nullable();
            $table->boolean('requires_corrective_action')->default(true);
            $table->text('corrective_action_description')->nullable();
            $table->foreignId('remediation_id')->nullable();
            $table->date('corrective_action_deadline')->nullable();
            $table->date('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['severity'], 'audit_findings_severity_index');
            $table->index(['status'], 'audit_findings_status_index');
            $table->comment('Registro dei rilievi (anomalie/non conformità) emersi durante gli audit');
        });

        Schema::create('audit_checklist_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id');
            $table->unsignedBigInteger('audit_checklist_item_id')->comment('Riferimento debole a audit_checklist_items (connessione di default)');
            $table->unsignedBigInteger('external_processor_id')->nullable()->comment('Riferimento debole a external_processors (connessione di default)');
            $table->text('internal_documentation')->nullable()->comment('Documentazione interna/policy disponibile (Titolare/Responsabile)');
            $table->text('vendor_evidence_notes')->nullable()->comment('Evidenza caso specifico / sub-fornitore');
            $table->string('gap_status')->default('da_verificare')->comment('Enum App\\\\Enums\\\\AuditChecklistGapStatus');
            $table->text('gap_notes')->nullable();
            $table->boolean('is_vendor_scope')->default(false)->comment('true se il gap è attribuibile al sub-fornitore/vendor');
            $table->boolean('is_optional')->default(false);
            $table->date('verified_at')->nullable();
            $table->date('next_review_at')->nullable();
            $table->foreignId('verified_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['audit_id', 'audit_checklist_item_id'], 'audit_checklist_eval_unique');
        });

        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->comment('Logical FK: db_bpm.companies');
            $table->morphs('complaintable');
            $table->date('received_at');
            $table->string('subject');
            $table->text('description');
            $table->string('status')->comment('Enum: ricevuto, in_lavorazione, accolto, respinto');
            $table->date('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status'], 'complaints_status_index');
            $table->index(['received_at'], 'complaints_received_at_index');
            $table->comment('Registro delle segnalazioni e reclami ricevuti');
        });

        Schema::create('complaint_registry', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->comment('Logical FK: db_bpm.companies');
            $table->string('protocol_number', 50)->nullable()->comment('Protocollo interno univoco');
            $table->date('received_at')->nullable()->comment('Data ufficiale di ricezione');
            $table->string('reception_channel', 50)->nullable()->comment('Enum: pec, raccomandata, email, brevi_manu');
            $table->string('receiving_email')->nullable()->comment('La casella aziendale che ha ricevuto la notifica');
            $table->nullableMorphs('complainant');
            $table->string('complainant_name')->nullable()->comment('Nome se il reclamante non è censito a DB');
            $table->string('complainant_email')->nullable()->comment('Contatto del reclamante');
            $table->string('macro_category', 30)->nullable()->comment('Enum: financial, privacy, insurance, operational');
            $table->string('category', 50)->nullable()->comment('Enum: delay, behavior, fraud, gdpr_access, gdpr_erasure');
            $table->nullableMorphs('subject');
            $table->foreignId('agent_id')->nullable()->comment('Collaboratore/Agente della rete coinvolto');
            $table->foreignId('bank_id')->nullable()->comment('Banca Mandante / Ente Erogante coinvolto');
            $table->text('description')->comment('Testo principale del reclamo');
            $table->decimal('financial_impact', 10, 2)->default(0.00)->comment('Eventuale richiesta danni o rimborso in EUR');
            $table->string('status', 30)->nullable()->default('open')->comment('Enum: open, investigating, accepted, rejected, escalated');
            $table->date('deadline_at')->nullable()->comment('Scadenza legale (es. 60gg per bancari, 30gg per GDPR)');
            $table->boolean('is_extended')->default(false)->comment('Se i termini sono stati estesi legalmente');
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable()->comment('Esito dell\'istruttoria e motivazioni');
            $table->string('escalated_to', 50)->nullable()->comment('Se rigettato, eventuale ricorso a: abf, oam, ivass, garante');
            $table->unsignedBigInteger('data_subject_request_id')->nullable();
            $table->unsignedInteger('event_sequence')->nullable();
            $table->dateTime('event_at')->nullable();
            $table->string('event_phase')->nullable();
            $table->string('mandating_company')->nullable();
            $table->string('master_agency')->nullable();
            $table->string('sub_supplier')->nullable();
            $table->string('caller_number')->nullable();
            $table->string('agcom_roc_compliance')->nullable();
            $table->string('event_channel_label')->nullable();
            $table->string('event_direction')->nullable();
            $table->string('event_counterparty')->nullable();
            $table->string('complainant_phone')->nullable();
            $table->string('complainant_fiscal_code')->nullable();
            $table->text('operational_action')->nullable();
            $table->string('dnc_blacklist_status')->nullable();
            $table->string('sla_deadline_note')->nullable();
            $table->text('log_freeze_retention')->nullable();
            $table->string('evidence_attachment')->nullable();
            $table->string('phase_status')->nullable();
            $table->string('assigned_to')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['protocol_number', 'event_sequence'], 'complaint_registry_protocol_number_event_sequence_unique');
            $table->index(['company_id', 'status'], 'complaint_registry_company_id_status_index');
            $table->index(['deadline_at'], 'complaint_registry_deadline_at_index');
            $table->index(['macro_category', 'category'], 'complaint_registry_macro_category_category_index');
            $table->index(['protocol_number'], 'complaint_registry_protocol_number_index');
            $table->index(['data_subject_request_id'], 'complaint_registry_data_subject_request_id_index');
            $table->comment('Registro ufficiale Reclami e Segnalazioni (OAM, IVASS, Privacy)');
        });

        Schema::create('suspicious_activity_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->comment('Logical FK: db_bpm.companies');
            $table->foreignId('client_id')->nullable();
            $table->morphs('reportable');
            $table->timestamp('reported_at')->nullable();
            $table->json('anomalies_codes')->nullable();
            $table->text('description');
            $table->enum('status', ['pending', 'investigated', 'reported', 'archived'])->default('pending');
            $table->timestamps();
            $table->softDeletes();
            $table->comment('Registro dettaglio delle segnalazioni di attività sospette');
        });

        Schema::create('training_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->morphs('trainable');
            $table->enum('regulatory_framework', ['gdpr', 'oam', 'ivass', 'sicurezza_lavoro', 'antiriciclaggio', 'mifid', 'other'])->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('provider')->nullable();
            $table->string('trainer')->nullable();
            $table->enum('delivery_mode', ['in_person', 'online', 'blended', 'on_the_job', 'webinar'])->default('in_person');
            $table->date('training_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('hours', 5, 1)->default(0.0);
            $table->enum('outcome', ['passed', 'failed', 'attended', 'partial'])->default('attended');
            $table->decimal('score', 5, 2)->nullable();
            $table->boolean('certificate_issued')->default(false);
            $table->string('certificate_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
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
