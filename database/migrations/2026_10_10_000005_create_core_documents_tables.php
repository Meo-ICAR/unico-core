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
            $table->string('description')->nullable()->comment('Descrizione aggiuntiva');
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
            $table->boolean('is_endMonth')->default(false)->comment('Approssima data a fine mese');
            $table->boolean('is_AiAbstract')->default(false)->comment('Ask AI to make abstract');
            $table->boolean('is_AiCheck')->default(false)->comment('AI conformity required');
            $table->text('AiPattern')->nullable()->comment('How AI can detect document is of this type');
            $table->unsignedTinyInteger('min_confidence')->default(70)->comment('Soglia minima per suggerire il tipo');
            $table->boolean('allow_auto_verification')->default(false)->comment('Valida da solo se confidence alta');
            $table->json('notify_days_before')->nullable()->comment('Es. [30, 15, 5] giorni prima');
            $table->unsignedTinyInteger('retention_years')->nullable()->comment('GDPR retention policy (anni)');
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->foreignId('deleted_by')->nullable();
            $table->boolean('is_versioned')->nullable()->default(false)->comment('Mantieni lo storico');
            $table->string('document_typable')->nullable()->comment('Alias del modello polimorfo a cui si applica il documento');
            $table->string('trigger_state')->nullable()->comment('filled, empty, equals');
            $table->string('trigger_value')->nullable()->comment('Il valore specifico da controllare');
            $table->string('exclude_field')->nullable()->comment('Campo del modello da escludere se valorizzato');
            $table->string('exclude_state')->nullable()->comment('filled, empty, equals');
            $table->string('exclude_value')->nullable()->comment('Il valore specifico da controllare');
            $table->integer('expire_days_before')->nullable()->comment('Numero di giorni prima della scadenza per inviare la notifica');
            $table->timestamps();
            $table->softDeletes();
            $table->comment('Anagrafica delle tipologie documentali con regole di validazione, AI e GDPR');
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->comment('ID Tenant proprietario');
            $table->morphs('documentable');
            $table->foreignId('document_type_id')->nullable();
            $table->string('name')->nullable()->comment('Titolo logico o nome file del documento');
            $table->integer('training_hours')->nullable()->comment('Ore di formazione richieste');
            $table->enum('training_organization', ['interna', 'OAM', 'IVASS', 'PRIVACY'])->nullable()->comment('Formazione per organizzazione');
            $table->string('docnumber')->nullable()->comment('Numero protocollo o identificativo del documento');
            $table->string('spatie_collection', 100)->default('default')->comment('Nome della collection per Spatie Media Library');
            $table->string('document_url')->nullable()->comment('URL pubblico o percorso del documento sul web/storage');
            $table->string('status', 50)->default('uploaded')->comment('Stato del file: uploaded, verified, rejected, expired');
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
            $table->boolean('is_endMonth')->default(false)->comment('Approssima la scadenza all\'ultimo giorno del mese');
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
            $table->foreignId('renewed_by_id')->nullable()->comment('Documento che sostituisce questo (es. versione firmata)');
            $table->foreignId('uploaded_by')->nullable()->comment('FK Utente che ha eseguito l\'upload');
            $table->foreignId('verified_by')->nullable()->comment('FK Admin che ha verificato e approvato');
            $table->timestamp('verified_at')->nullable()->comment('Data e ora di verifica');
            $table->foreignId('created_by')->nullable()->comment('FK Utente creatore del record');
            $table->foreignId('updated_by')->nullable()->comment('FK Utente che ha aggiornato il record');
            $table->foreignId('deleted_by')->nullable()->comment('FK Utente che ha eliminato il record');
            $table->string('file_hash', 64)->nullable()->comment('Hash SHA-256 del file per prevenire duplicati esatti');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['expires_at'], 'doc_expires_at_index');
            $table->index(['status'], 'doc_status_index');
            $table->comment('Archivio centralizzato dei documenti, allegati e file con supporto OCR e AI');
        });

        Schema::create('document_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id');
            $table->unsignedSmallInteger('days_before')->comment('Giorni mancanti alla scadenza al momento dell\'invio');
            $table->string('recipient_email');
            $table->string('status', 20)->default('sent');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->unique(['document_id', 'days_before'], 'document_reminders_document_days_unique');
            $table->index(['sent_at'], 'document_reminders_sent_at_index');
        });

        Schema::create('document_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id');
            $table->morphs('documentable');
            $table->string('entity_name');
            $table->string('documentable_group_key');
            $table->string('document_name');
            $table->string('document_type_name');
            $table->date('expires_at')->nullable();
            $table->integer('days_until_expiry');
            $table->string('status');
            $table->timestamp('last_sent_at')->nullable()->comment('Data ultimo sollecito inviato');
            $table->integer('reminders_count')->default(0);
            $table->timestamps();
            $table->index(['expires_at', 'status'], 'document_schedules_expires_at_status_index');
            $table->index(['documentable_group_key'], 'document_schedules_documentable_group_key_index');
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('app_identifier', 50)->nullable();
            $table->string('taskable')->nullable();
            $table->string('trigger_field')->nullable()->comment('Campo del modello da controllare');
            $table->string('trigger_state')->nullable()->comment('filled, empty, equals');
            $table->string('trigger_value')->nullable()->comment('Il valore specifico da controllare');
            $table->string('trigger_subfield')->nullable()->comment('Secondo campo del modello da controllare (es. stato_pratica), in aggiunta a trigger_field');
            $table->string('trigger_subvalue')->nullable()->comment('Valore che trigger_subfield deve avere (uguale a)');
            $table->string('exclude_field')->nullable()->comment('Campo del modello da escludere se valorizzato');
            $table->string('exclude_state')->nullable()->comment('filled, empty, equals');
            $table->string('exclude_value')->nullable()->comment('Il valore specifico da controllare');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['name'], 'tasks_name_unique');
            $table->index(['app_identifier'], 'tasks_app_identifier_index');
        });

        Schema::create('task_document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id');
            $table->foreignId('document_type_id');
            $table->string('slug')->nullable()->comment('Slug univoco per URL');
            $table->boolean('is_required')->default(true)->comment('Se il documento è obbligatorio per questo task');
            $table->timestamps();
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->morphs('model');
            $table->char('uuid', 36)->nullable();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable();
            $table->timestamps();
            $table->unique(['uuid'], 'media_uuid_unique');
            $table->index(['order_column'], 'media_order_column_index');
            $table->comment('Registro dei media caricati nel sistema');
        });

        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code')->comment('Codice univoco di email (es. AUDIT_REMOTE)');
            $table->string('emailable')->nullable();
            $table->string('email_field')->nullable()->comment('Campo del modello contenente l\'email del sender');
            $table->string('trigger_field')->nullable()->comment('Campo del modello da controllare');
            $table->string('trigger_state')->nullable()->comment('filled, empty, equals');
            $table->string('trigger_value')->nullable()->comment('Il valore specifico da controllare');
            $table->string('name')->comment('Nome descrittivo per gli utenti');
            $table->string('subject')->comment('Oggetto della mail');
            $table->longText('body')->comment('Corpo della mail (supporta HTML e placeholders)');
            $table->json('placeholders')->nullable()->comment('Elenco delle variabili utilizzabili (es. {agente_nome})');
            $table->boolean('is_active')->default(true)->comment('Stato del template');
            $table->string('app_identifier', 50)->nullable();
            $table->timestamps();
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
            $table->string('smtp_host')->comment('Host del server di posta in uscita (es. smtps.pec.it)');
            $table->integer('smtp_port')->comment('Porta del server di posta in uscita (es. 465)');
            $table->string('smtp_username')->comment('Username per la posta in uscita');
            $table->text('smtp_password')->comment('Password criptata per la posta in uscita');
            $table->enum('smtp_encryption', ['none', 'ssl', 'tls'])->default('ssl')->comment('Tipo di crittografia per la posta in uscita');
            $table->boolean('is_active')->default(true)->comment('Indica se l account è attualmente attivo');
            $table->nullableMorphs('mailable');
            $table->foreignId('company_id')->nullable()->comment('Tenant proprietario della casella');
            $table->enum('auth_type', ['password', 'oauth2'])->default('password')->comment('Metodo di autenticazione');
            $table->string('provider')->nullable()->comment('Provider OAuth (google, microsoft)');
            $table->text('access_token')->nullable()->comment('Access token OAuth2, cifrato');
            $table->text('refresh_token')->nullable()->comment('Refresh token OAuth2, cifrato');
            $table->timestamp('token_expires_at')->nullable()->comment('Scadenza dell\'access token');
            $table->timestamp('last_synced_at')->nullable()->comment('Ultima scansione IMAP completata');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['email_address'], 'mail_accounts_email_address_unique');
            $table->comment('Caselle email e PEC: IMAP in ingresso e SMTP in uscita, con Basic Auth o OAuth 2.0');
        });

        Schema::create('incoming_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->comment('Tenant proprietario della casella');
            $table->foreignId('mail_account_id')->nullable();
            $table->string('message_id')->comment('Message-Id RFC 5322, senza parentesi angolari');
            $table->string('in_reply_to')->nullable();
            $table->text('references')->nullable()->comment('Catena References dell\'header, separata da spazi');
            $table->string('thread_id')->nullable()->comment('Conversazione: prima reference o message_id');
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->string('subject')->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->timestamp('received_at')->comment('Data del messaggio dal server');
            $table->boolean('is_read')->default(false);
            $table->string('classification', 50)->nullable()->comment('Esito del classificatore');
            $table->nullableMorphs('related');
            $table->string('drive_folder_id')->nullable()->comment('Cartella Drive in cui è archiviata');
            $table->timestamps();
            $table->softDeletes();
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
        Schema::dropIfExists('task_document_types');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('document_schedules');
        Schema::dropIfExists('document_reminders');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_types');
    }
};
