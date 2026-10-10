<?php

namespace Unico\Core\Concerns;

/**
 * Fa leggere e scrivere il modello sul database core, la cui connessione
 * si sceglie in config/unico-core.php.
 */
trait UsesCoreConnection
{
    public function getConnectionName(): ?string
    {
        return config('unico-core.connection');
    }
}
