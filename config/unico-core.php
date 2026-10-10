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
    | `analysis.auto`: al caricamento di un file (Spatie) l'analisi parte in coda.
    | `ai`: senza `api_key` la lettura AI è spenta (restano verifica tecnica e regex).
    |   I prezzi sono in dollari per milione di token, per calcolare `document_analyses.ai_cost`.
    | `checks`: codice => classe. Le app aggiungono i propri con CheckRegistry::extend().
    | `default_checks`: controlli se il tipo documento non ne indica (`document_types.checks`).
    |
    */

    'documents' => [
        'analysis' => [
            'auto' => env('UNICO_CORE_ANALYSIS_AUTO', false),
            'queue' => env('UNICO_CORE_ANALYSIS_QUEUE', 'documents'),
        ],
        'ai' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('UNICO_CORE_AI_MODEL', 'claude-sonnet-5-5'),
            'price_input' => env('UNICO_CORE_AI_PRICE_INPUT'),
            'price_output' => env('UNICO_CORE_AI_PRICE_OUTPUT'),
        ],
        'checks' => [
            'leggibile' => \Unico\Core\Documents\Checks\ReadableCheck::class,
            'tipo_documento' => \Unico\Core\Documents\Checks\KindCheck::class,
            'dati_coerenti' => \Unico\Core\Documents\Checks\MatchesDeclaredCheck::class,
            'non_scaduto' => \Unico\Core\Documents\Checks\NotExpiredCheck::class,
        ],
        'default_checks' => [
            '*' => ['leggibile', 'tipo_documento', 'dati_coerenti'],
            'identita' => ['leggibile', 'tipo_documento', 'dati_coerenti', 'non_scaduto'],
        ],
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

];
