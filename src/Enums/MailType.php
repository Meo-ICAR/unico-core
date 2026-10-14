<?php

namespace Unico\Core\Enums;

/**
 * Tipo di email. Vale sia per le caselle (`mail_accounts.type`: a cosa serve la casella) sia per i template (`email_templates.type`):
 * il mittente di un invio si sceglie per company e tipo (`Mail\SenderAccountResolver`). Le risposte arrivano alla stessa casella
 * e le legge `MailFetcher`.
 */
enum MailType: string
{
    case Sollecito = 'sollecito';
    case Processo = 'processo';
    case Generale = 'generale';
}
