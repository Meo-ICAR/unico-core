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
    | Classe usata per le relazioni "utente" dei documenti. Se nullo si usa
    | il modello del provider `users` in config/auth.php.
    |
    */

    'user_model' => null,

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
