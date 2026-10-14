<?php

namespace Unico\Core\Console;

use Illuminate\Console\Command;
use Unico\Core\Enums\MailType;
use Unico\Core\Models\MailAccount;

class MailAccountFromEnvCommand extends Command
{
    protected $signature = 'unico-core:mail-account-from-env';

    protected $description = 'Crea o aggiorna la casella di posta (tipo sollecito) con i parametri IMAP_* e MAIL_* del .env, per leggere le risposte';

    public function handle(): int
    {
        $config = (array) config('unico-core.practice_intake.env_mail_account');

        foreach (['imap_host', 'imap_username', 'imap_password', 'email_address'] as $required) {
            if (blank($config[$required] ?? null)) {
                $this->error("Manca {$required}: controlla IMAP_* e MAIL_FROM_ADDRESS nel .env.");

                return self::FAILURE;
            }
        }

        $account = MailAccount::query()->updateOrCreate(
            ['email_address' => $config['email_address'], 'type' => MailType::Sollecito->value],
            [
                'name' => $config['name'],
                'protocol' => 'imap',
                'imap_host' => $config['imap_host'],
                'imap_port' => (int) $config['imap_port'],
                'imap_encryption' => $config['imap_encryption'],
                'imap_username' => $config['imap_username'],
                'imap_password' => $config['imap_password'],
                'smtp_host' => $config['smtp_host'],
                'smtp_port' => (int) $config['smtp_port'],
                'smtp_encryption' => $config['smtp_encryption'],
                'smtp_username' => $config['smtp_username'],
                'smtp_password' => $config['smtp_password'],
                'is_active' => true,
            ],
        );

        $this->info("Casella #{$account->id} ({$account->email_address}) pronta: si legge con `unico-core:mail-fetch {$account->id}`.");

        return self::SUCCESS;
    }
}
