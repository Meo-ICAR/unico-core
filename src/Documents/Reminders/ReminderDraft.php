<?php

namespace Unico\Core\Documents\Reminders;

use Illuminate\Support\Collection;
use Unico\Core\Models\Document;
use Unico\Core\Models\EmailTemplate;
use Unico\Core\Models\MailAccount;

/**
 * Sollecito composto ma non ancora inviato: l'app lo mostra (destinatario, casella mittente, oggetto, corpo), l'utente può
 * cambiare oggetto e corpo e poi si invia con `DocumentReminderService::send()`.
 */
final class ReminderDraft
{
    /** @param Collection<int, Document> $documents */
    public function __construct(
        public readonly Collection $documents,
        public readonly string $recipientName,
        public readonly ?string $recipientEmail,
        public readonly string $severity,
        public readonly ?EmailTemplate $template,
        public readonly ?MailAccount $sender,
        public string $subject,
        public string $body,
    ) {}
}
