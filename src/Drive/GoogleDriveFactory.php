<?php

namespace Unico\Core\Drive;

use Google\Client;
use Google\Service\Drive;
use RuntimeException;

/**
 * Unico punto in cui si leggono le credenziali Google e si costruisce il client Drive.
 *
 * Il service account non ha quota di archiviazione propria: sulle cartelle di «Il mio Drive»
 * l'upload fallisce con `storageQuotaExceeded`. Le soluzioni sono due: usare solo Drive condivisi,
 * oppure la delega a livello di dominio (`unico-core.drive.impersonate`), con cui i file
 * appartengono a un utente reale del dominio Workspace.
 */
class GoogleDriveFactory
{
    public static function make(bool $readOnly = false): Drive
    {
        $path = config('unico-core.drive.credentials') ?: storage_path('app/google-credentials.json');

        if (! is_file($path)) {
            throw new RuntimeException("File di credenziali Google non trovato in: {$path}");
        }

        $client = new Client;
        $client->setAuthConfig($path);
        $client->addScope($readOnly ? Drive::DRIVE_READONLY : Drive::DRIVE);

        if ($subject = config('unico-core.drive.impersonate')) {
            $client->setSubject($subject);
        }

        return new Drive($client);
    }
}
