<?php

namespace Unico\Core\Events;

use Unico\Core\Models\IncomingEmail;

/**
 * Emesso dopo aver salvato una nuova email, con gli allegati già archiviati.
 * Le app lo ascoltano per le proprie azioni: unicogdpr apre richieste e reclami,
 * unicobpm collega gli allegati ai processi, unicoloan alle pratiche.
 */
class IncomingEmailReceived
{
    public function __construct(public IncomingEmail $email) {}
}
