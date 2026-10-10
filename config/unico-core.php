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
    | Rotte
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'enabled' => true,
        'prefix' => '',
        'middleware' => ['web', 'auth'],
    ],

];
