<?php

use Unico\Core\Access\AccessChecker;

if (! function_exists('checkPiano')) {
    /**
     * Verifica se una funzionalità è accessibile all'utente: piano della sua company per questa app e ruoli EmployeeType
     * (vedi Unico\Core\Access\AccessChecker). Il risultato è memorizzato per la durata della richiesta.
     * `$write`: la funzione modifica i dati; i ruoli di sola lettura (l'ispettore) la ottengono solo in lettura.
     */
    function checkPiano(string $feature, ?string $callerClass = null, bool $write = false): bool
    {
        return app(AccessChecker::class)->check($feature, $callerClass, null, $write);
    }
}
