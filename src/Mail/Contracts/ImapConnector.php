<?php

namespace Unico\Core\Mail\Contracts;

use Unico\Core\Models\MailAccount;

/**
 * Crea un client IMAP a partire da una casella. È un'interfaccia perché nei test
 * la casella si simula senza collegarsi a un server.
 */
interface ImapConnector
{
    /** Client configurato, non ancora connesso: la connessione spetta a chi lo usa. */
    public function make(MailAccount $account): object;
}
