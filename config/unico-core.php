<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connessione del database core
    |--------------------------------------------------------------------------
    |
    | Nome della connessione (definita in config/database.php dell'app) che
    | punta al database con le tabelle condivise. Può essere lo stesso database
    | dell'app o uno separato.
    |
    */

    'connection' => env('UNICO_CORE_DB_CONNECTION', 'core'),

    /*
    |--------------------------------------------------------------------------
    | Tabella delle migration core
    |--------------------------------------------------------------------------
    |
    | Le migration core sono registrate in una tabella propria, così ogni app
    | può tenere la sua tabella "migrations" anche sullo stesso database.
    |
    */

    'migrations_table' => env('UNICO_CORE_MIGRATIONS_TABLE', 'migrations_core'),

    /*
    |--------------------------------------------------------------------------
    | Import anagrafiche da dump di produzione
    |--------------------------------------------------------------------------
    |
    | Nomi dei database MySQL sorgente (stesso server della connessione core).
    | Comando: php artisan unico-core:import-anagrafiche
    | Dry-run di default; --execute scrive sul database core.
    | employee_types, companies, employees da OAM; fornitoris e clientis da
    | Proforma; document_types da OAM + Loan + Agent (merge per slug/nome).
    | users: super_admin hassistosrl@gmail.com, employees (@races.it / client),
    | fornitoris Proforma con role fornitore.
    |
    */

    'import' => [
        'oam' => env('UNICO_CORE_IMPORT_OAM', 'unicooam'),
        'proforma' => env('UNICO_CORE_IMPORT_PROFORMA', 'proforma'),
        'loan' => env('UNICO_CORE_IMPORT_LOAN', 'unicoloan'),
        'agent' => env('UNICO_CORE_IMPORT_AGENT', 'unicoagent'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Disco dei documenti
    |--------------------------------------------------------------------------
    |
    | Disco privato (mai `public`) dove vanno i file dei documenti: carte
    | d'identità, buste paga e altri dati personali. Se l'app non lo definisce,
    | il pacchetto registra un disco locale in storage/app/private/documents.
    | Il download passa sempre dalla rotta `documents.download`.
    |
    */

    'documents_disk' => env('UNICO_CORE_DOCUMENTS_DISK', 'documents'),

    'documents_root' => env('UNICO_CORE_DOCUMENTS_ROOT'),

    /*
    |--------------------------------------------------------------------------
    | DocumentsRelationManager
    |--------------------------------------------------------------------------
    |
    | oam_semester_filter: mostra il filtro «solo semestre in corso» (unicooam).
    | task_model: classe plichi con getAvailableFor / generateDocumentsFor (unicoloan).
    | export_class: export Excel Filament (es. App\Filament\Exports\DynamicGroupExport).
    |
    */

    'documents' => [
        'oam_semester_filter' => env('UNICO_CORE_DOCUMENTS_OAM_SEMESTER_FILTER', false),
        'task_model' => env('UNICO_CORE_DOCUMENTS_TASK_MODEL'),
        'export_class' => env('UNICO_CORE_DOCUMENTS_EXPORT_CLASS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Modello utente
    |--------------------------------------------------------------------------
    |
    | Classe usata dai documenti per le relazioni "utente". Se nullo si usa
    | Unico\Core\Models\User; un'app con un proprio modello User sulla stessa
    | tabella può indicarlo qui.
    |
    */

    'user_model' => null,

    /*
    |--------------------------------------------------------------------------
    | Alias dei modelli polimorfici
    |--------------------------------------------------------------------------
    |
    | Il pacchetto registra gli alias di Unico\Core\MorphMap. Con `true` Laravel
    | rifiuta di salvare una relazione polimorfica il cui modello non ha un alias,
    | invece di scrivere il nome della classe nel database.
    |
    */

    'enforce_morph_map' => env('UNICO_CORE_ENFORCE_MORPH_MAP', false),

    /*
    |--------------------------------------------------------------------------
    | Posta
    |--------------------------------------------------------------------------
    |
    | `rules` aggiunge classi al classificatore: ['etichetta' => ['/regex/iu']],
    | valutate nell'ordine dopo mancati recapiti, notifiche dei provider e inviti.
    | La scansione guarda indietro di `overlap_days` giorni dall'ultima sincronizzazione
    | (`initial_days` alla prima) e deduplica per message_id.
    |
    */

    'mail' => [
        'queue' => env('UNICO_CORE_MAIL_QUEUE', 'mail'),
        'limit' => 50,
        'overlap_days' => 2,
        'initial_days' => 30,
        'validate_cert' => true,
        'rules' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Drive
    |--------------------------------------------------------------------------
    |
    | `credentials`: file JSON del service account (default storage/app/google-credentials.json).
    | `impersonate`: email di un utente del dominio Workspace per la delega a livello di dominio;
    |   senza, i file si possono creare solo su Drive condivisi (il service account non ha quota).
    | `root_folder_id`: cartella madre delle company senza capogruppo.
    | `company_initial_folders` / `company_folders`: sottocartelle create con la company e
    |   quando diventa attiva; accettano percorsi come 'RELAZIONE DPO/2026'.
    | `audit_buckets`: gruppo di cartelle per alias del soggetto controllato.
    |
    */

    'drive' => [
        'credentials' => env('GOOGLE_DRIVE_CREDENTIALS'),
        'impersonate' => env('GOOGLE_DRIVE_IMPERSONATE'),
        'root_folder_id' => env('UNICO_CORE_DRIVE_ROOT'),
        'company_initial_folders' => ['STARTUP'],
        'company_folders' => ['NOMINE', 'RECLAMI', 'WEB', 'IT', 'DIPENDENTI', 'TRATTAMENTI', 'FORNITORI', 'MANDATARIE'],
        'audit_folder' => 'AUDIT',
        'audit_buckets' => [
            'fornitore' => 'FORNITORI',
            'cliente' => 'MANDATARIE',
            'client' => 'MANDATARIE',
        ],
        'email_folder' => 'RECLAMI',
    ],

    /*
    |--------------------------------------------------------------------------
    | Documenti: analisi automatica
    |--------------------------------------------------------------------------
    |
    | Pipeline verifica tecnica -> classificazione -> lettura AI con controlli a plug-in.
    | `analysis.auto`: al caricamento di un file (Spatie) l'analisi parte in coda (`analysis.sync`: subito).
    | `ai`: senza `api_key` la lettura AI è spenta (restano verifica tecnica e regex).
    |   I prezzi sono in dollari per milione di token, per calcolare `document_analyses.ai_cost`.
    | `checks`: codice => classe. Le app aggiungono i propri con CheckRegistry::extend().
    | `default_checks`: controlli se il tipo documento non ne indica (`document_types.checks`).
    |
    */

    'signature' => [
        // Modulistica di una pratica da stampare e firmare (componente PracticeSignaturePackageForm).
        'practice_package' => env('UNICO_CORE_SIGNATURE_PRACTICE_PACKAGE', true),
        // Firma elettronica: `fake` (sviluppo e test) o `yousign`.
        'driver' => env('SIGNATURE_DRIVER', 'fake'),
        'ttl_days' => (int) env('SIGNATURE_REQUEST_TTL_DAYS', 7),
        'sync' => [
            'stale_minutes' => 30,
            'limit' => 20,
        ],
        'yousign' => [
            'api_key' => env('YOUSIGN_API_KEY'),
            'base_url' => env('YOUSIGN_BASE_URL', 'https://api-sandbox.yousign.app/v3'),
            'webhook_secret' => env('YOUSIGN_WEBHOOK_SECRET'),
            'signature_level' => env('YOUSIGN_SIGNATURE_LEVEL', 'electronic_signature'),
            'timeout' => 30,
        ],
        // Rotta del webhook (POST signature/webhook/{provider}): senza sessione né CSRF.
        'webhook_enabled' => env('UNICO_CORE_SIGNATURE_WEBHOOK', true),
        'webhook_middleware' => ['api'],
    ],

    'pdf' => [
        // Moduli PDF compilabili (AcroForm): binario pdftk e disco dove stanno i modelli (file_path di pdf_modules).
        'pdftk_binary' => env('PDFTK_BINARY', 'pdftk'),
        'modules_disk' => env('UNICO_CORE_PDF_MODULES_DISK', 'public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Acquisizione dei documenti di una pratica
    |--------------------------------------------------------------------------
    |
    | Caricamento unico dei documenti richiesti (PracticeDocumentIntake e il componente Filament
    | PracticeDocumentsUpload): il file si classifica (regex sul nome, poi AI), si leggono i dati
    | e, con `populate_client`, si compila l'anagrafica del richiedente.
    |
    */

    'practice_intake' => [
        'enabled' => env('UNICO_CORE_PRACTICE_INTAKE', true),
        'populate_client' => env('UNICO_CORE_PRACTICE_INTAKE_POPULATE_CLIENT', true),
        // Richiesta dei documenti al cliente per email e acquisizione degli allegati della risposta.
        'mail_replies' => env('UNICO_CORE_PRACTICE_INTAKE_MAIL_REPLIES', true),
        // Template email (email_templates.code) della richiesta: il grado (severity) sceglie fra richiesta e solleciti.
        'request_template' => env('UNICO_CORE_PRACTICE_REQUEST_TEMPLATE', 'PRATICA_DOCUMENTI_RICHIESTI'),
        // Casella creata da `unico-core:mail-account-from-env`: IMAP_* e MAIL_* del .env (es. OVH).
        'env_mail_account' => [
            'name' => env('UNICO_CORE_ENV_MAIL_ACCOUNT_NAME', 'Casella del .env'),
            'imap_host' => env('IMAP_HOST'),
            'imap_port' => env('IMAP_PORT', 993),
            'imap_encryption' => env('IMAP_ENCRYPTION', 'ssl'),
            'imap_username' => env('IMAP_USERNAME'),
            'imap_password' => env('IMAP_PASSWORD'),
            'smtp_host' => env('MAIL_HOST'),
            'smtp_port' => env('MAIL_PORT', 465),
            'smtp_encryption' => env('MAIL_ENCRYPTION', 'ssl'),
            'smtp_username' => env('MAIL_USERNAME'),
            'smtp_password' => env('MAIL_PASSWORD'),
            'email_address' => env('MAIL_FROM_ADDRESS'),
        ],
    ],

    'documents' => [
        // Percorsi leggibili dei file (azienda / tipo proprietario / istanza) al posto delle cartelle numeriche di Spatie.
        'readable_paths' => env('UNICO_CORE_READABLE_PATHS', true),
        'analysis' => [
            'auto' => env('UNICO_CORE_ANALYSIS_AUTO', false),
            // true: l'analisi gira subito, nella richiesta di caricamento (di norma solo verifica tecnica), invece che in coda.
            'sync' => env('UNICO_CORE_ANALYSIS_SYNC', false),
            'queue' => env('UNICO_CORE_ANALYSIS_QUEUE', 'documents'),
        ],
        'ai' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('UNICO_CORE_AI_MODEL', 'claude-sonnet-5-5'),
            'price_input' => env('UNICO_CORE_AI_PRICE_INPUT'),
            'price_output' => env('UNICO_CORE_AI_PRICE_OUTPUT'),
        ],
        'reminders' => [
            'window_days' => 90,
            'resend_after_days' => 5,
            'missing_reminder_days' => 7,
            'default_notify_days_before' => [30, 15, 7, 1, 0],
            'template_code' => 'DOC_EXPIRING',
            // Dominio degli identificativi di conversazione dei solleciti (<doc-ID@dominio>); vuoto = host di APP_URL.
            'thread_domain' => env('UNICO_CORE_REMINDER_THREAD_DOMAIN'),
            // Grado del sollecito (template per severity): il peggiore tra scadenza e solleciti già inviati. Giorni mancanti: sotto
            // `alert` → alert (scaduto), fino a `warning` → warning, fino a `regular` → regular. Solleciti già inviati: da n in su.
            'escalation' => [
                'days' => ['alert' => 0, 'warning' => 7, 'regular' => 30],
                'sent' => ['regular' => 1, 'warning' => 2, 'alert' => 3],
            ],
            'fallback_email' => env('UNICO_CORE_REMINDER_FALLBACK_EMAIL'),
            // Per alias del soggetto: campi provati in ordine ('a+b' unisce due campi). Le app aggiungono i propri alias.
            'recipients' => [
                'user' => ['label' => 'Utente', 'name' => ['name'], 'email' => ['email']],
                'employee' => ['label' => 'Dipendente', 'name' => ['name'], 'email' => ['email', 'pec']],
                'fornitore' => ['label' => 'Fornitore', 'name' => ['nome', 'name'], 'email' => ['email', 'email_private', 'pec']],
                'cliente' => ['label' => 'Cliente', 'name' => ['nome', 'name'], 'email' => ['email', 'privacy_contact_email', 'dpo_email']],
                'client' => ['label' => 'Cliente', 'name' => ['name+first_name'], 'email' => ['email', 'pec']],
                'company' => ['label' => 'Azienda', 'name' => ['name'], 'email' => []],
                'audit' => ['label' => 'Audit', 'name' => ['title'], 'email' => []],
                'complaint_registry' => ['label' => 'Reclamo', 'name' => ['complainant_name', 'protocol_number'], 'email' => ['complainant_email', 'receiving_email']],
            ],
        ],
        'checks' => [
            'leggibile' => \Unico\Core\Documents\Checks\ReadableCheck::class,
            'tipo_documento' => \Unico\Core\Documents\Checks\KindCheck::class,
            'dati_coerenti' => \Unico\Core\Documents\Checks\MatchesDeclaredCheck::class,
            'non_scaduto' => \Unico\Core\Documents\Checks\NotExpiredCheck::class,
            'informativa_firmata' => \Unico\Core\Documents\Checks\InformativaFirmataCheck::class,
        ],
        'default_checks' => [
            '*' => ['leggibile', 'tipo_documento', 'dati_coerenti'],
            'identita' => ['leggibile', 'tipo_documento', 'dati_coerenti', 'non_scaduto'],
            'informativa' => ['leggibile', 'tipo_documento', 'informativa_firmata'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SharePoint
    |--------------------------------------------------------------------------
    |
    | Alternativa a Drive: i file restano in Spatie e vengono copiati nel drive SharePoint con
    | Microsoft Graph (app registration con permessi applicativi). `auto`: ogni file caricato
    | su un Document parte in coda. Le variabili SHARE_* sono quelle già usate da unicooam.
    |
    */

    'sharepoint' => [
        'tenant_id' => env('UNICO_CORE_SHAREPOINT_TENANT', env('SHARE_TENANT_ID')),
        'client_id' => env('UNICO_CORE_SHAREPOINT_CLIENT', env('SHARE_CLIENT_ID')),
        'client_secret' => env('UNICO_CORE_SHAREPOINT_SECRET', env('SHARE_SECRET_ID')),
        'drive_id' => env('UNICO_CORE_SHAREPOINT_DRIVE', env('SHARE_DRIVE_ID')),
        'upload_root' => env('UNICO_CORE_SHAREPOINT_ROOT', env('SHARE_UPLOAD_ROOT', 'Documenti')),
        'auto' => env('UNICO_CORE_SHAREPOINT_AUTO', false),
        'queue' => env('UNICO_CORE_SHAREPOINT_QUEUE', 'documents'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rotte
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'enabled' => true,
        'prefix' => '',
        'middleware' => ['web', 'auth'],
    ],

    // Identificativo dell'app (unicooam, unicoloan...): sceglie i template riservati all'app (`email_templates.app_identifier`).
    'app_identifier' => env('UNICO_CORE_APP_IDENTIFIER'),

    /*
    |--------------------------------------------------------------------------
    | Accesso: piano della company e ruoli
    |--------------------------------------------------------------------------
    | `plan`: forza il piano per tutte le company (BASE, MEDIUM, FULL, TRIAL; PLAN_TYPE), per sviluppo e test; vuoto = si legge
    |   `company_modules` della company dell'utente per l'app corrente. `default_plan`: se la company non ha il modulo.
    | `trial_expired_plan`: piano che vale dopo la fine del periodo di prova (`company_modules.trial_ends_at`).
    | `superadmin_roles` / `bypass_roles`: ruoli di `users.role` che hanno accesso totale a tutto (superadmin).
    | `admin_roles`: ruoli admin con accesso a tutti i model con company della propria azienda.
    | `readonly_roles`: ruoli che vedono tutto ciò che il piano della company copre ma non modificano nulla (l'ispettore).
    | `reference_roles`: mapping tra ruolo e model collegato tramite user_id (employee, client, fornitore).
    | `readonly_actions`: nomi delle azioni Filament che restano per i ruoli di sola lettura.
    |
    */
    'access' => [
        'plan' => env('PLAN_TYPE'),
        'default_plan' => 'FULL',
        'trial_expired_plan' => 'BASE',
        'superadmin_roles' => ['superadmin', 'super_admin'],
        'admin_roles' => ['admin'],
        'readonly_roles' => ['inspector'],
        'reference_roles' => [
            'employee' => \Unico\Core\Models\Employee::class,
            'client' => \Unico\Core\Models\Client::class,
            'fornitore' => \Unico\Core\Models\Fornitore::class,
        ],
        'bypass_roles' => ['superadmin', 'super_admin'],
        'deny_roles' => [],
        'readonly_actions' => ['view', 'download', 'preview', 'print', 'open'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bridge per sistemi esterni (unicobpm, app di terzi)
    |--------------------------------------------------------------------------
    | `enabled`: registra le rotte /api/commands (elenco, lancio in coda, esito del lancio) e /api/bridge/health.
    | `api_key`: chiave condivisa (X-Api-Key); senza, il bridge risponde 503. `middleware`: di default correlazione + chiave.
    | `commands`: nome => ['description' => ..., 'options' => [...]]: solo questi comandi, con queste opzioni (si somma a
    |   `schedulable_commands` se l'app lo ha). `queue`: coda dei lanci. `keep_runs_days`: per quanto restano i lanci.
    |
    */
    'bridge' => [
        'enabled' => env('UNICO_CORE_BRIDGE', false),
        'api_key' => env('BPM_BRIDGE_API_KEY'),
        'middleware' => [\Unico\Core\Bridge\Http\Middleware\CorrelationId::class, 'api', \Unico\Core\Bridge\Http\Middleware\VerifyBridgeKey::class],
        'commands' => [],
        'queue' => env('UNICO_CORE_BRIDGE_QUEUE'),
        'keep_runs_days' => 30,
    ],

];
