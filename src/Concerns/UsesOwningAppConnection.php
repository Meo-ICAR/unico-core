<?php

namespace Unico\Core\Concerns;

/**
 * La tabella appartiene a una sola app. Con la connessione di default
 * `mysql` il modello legge il database di quell'app; i test del pacchetto,
 * che non impostano quel default, restano sulla connessione core.
 */
trait UsesOwningAppConnection
{
    public function getConnectionName(): ?string
    {
        if (config('database.default') === 'mysql') {
            return 'mysql';
        }

        return config('unico-core.connection');
    }
}
