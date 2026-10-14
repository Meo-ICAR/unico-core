<?php

namespace Unico\Core\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Unico\Core\Models\MailAccount;

/**
 * Invia una Mailable con le credenziali SMTP della casella, così il messaggio parte
 * davvero dall'indirizzo della company. Il mailer è registrato al volo nella config
 * runtime, senza scrivere nulla su disco.
 */
class OutgoingMailer
{
    /** @param string|null $fromName Nome del mittente; se nullo si usa il nome della casella */
    public function send(MailAccount $account, string $to, Mailable $mailable, ?string $fromName = null): void
    {
        if (! $account->hasSmtpConfigured()) {
            throw new RuntimeException(
                "Casella #{$account->id} ({$account->email_address}): configurazione SMTP mancante, impossibile inviare come questa casella."
            );
        }

        $mailerName = 'mail_account_'.$account->id;

        config(["mail.mailers.{$mailerName}" => [
            'transport' => 'smtp',
            'host' => $account->smtp_host,
            'port' => $account->smtp_port ?: 587,
            'encryption' => $this->normalizeEncryption($account->smtp_encryption),
            'username' => $account->smtp_username ?: $account->email_address,
            'password' => $account->smtp_password,
        ]]);

        $mailable->from($account->email_address, $fromName ?? $account->name ?? $account->company?->name);

        Mail::mailer($mailerName)->to($to)->send($mailable);
    }

    private function normalizeEncryption(?string $encryption): ?string
    {
        return match ($encryption) {
            null, '', 'none', 'false' => null,
            default => $encryption,
        };
    }
}
