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

];
