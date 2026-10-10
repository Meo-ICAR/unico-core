<?php

namespace Unico\Core\Mail;

use Laravel\Socialite\Facades\Socialite;
use RuntimeException;
use Unico\Core\Mail\Contracts\ImapConnector;
use Unico\Core\Models\MailAccount;
use Webklex\PHPIMAP\ClientManager;

/**
 * Costruisce il client IMAP di una casella con Basic Auth oppure OAuth 2.0
 * (Google, Microsoft), rinnovando l'access token quando è scaduto o sta per scadere.
 * Il protocollo POP3 non è supportato da webklex/php-imap.
 */
class ImapConnectionFactory implements ImapConnector
{
    private ClientManager $clientManager;

    public function __construct(?ClientManager $clientManager = null)
    {
        $this->clientManager = $clientManager ?? new ClientManager;
    }

    public function make(MailAccount $account): object
    {
        if (($account->protocol ?? 'imap') !== 'imap') {
            throw new RuntimeException("Casella #{$account->id}: il protocollo {$account->protocol} non è supportato, solo IMAP.");
        }

        $oauth = $account->auth_type === 'oauth2';

        if ($oauth) {
            $this->refreshTokenIfNeeded($account);
        }

        return $this->clientManager->make([
            'host' => $account->imap_host,
            'port' => $account->imap_port,
            'encryption' => $this->normalizeEncryption($account->imap_encryption),
            'validate_cert' => config('unico-core.mail.validate_cert', true),
            'username' => $account->imap_username,
            'password' => $oauth ? $account->access_token : $account->imap_password,
            'protocol' => 'imap',
            'authentication' => $oauth ? 'oauth' : null,
        ]);
    }

    /** Rinnova l'access token tramite il refresh token e salva i nuovi valori (colonne cifrate). */
    public function refreshTokenIfNeeded(MailAccount $account): void
    {
        if ($account->auth_type !== 'oauth2' || ! $account->isTokenExpired()) {
            return;
        }

        if (blank($account->refresh_token)) {
            throw new RuntimeException(
                "Casella #{$account->id}: access token OAuth2 scaduto e nessun refresh token disponibile."
            );
        }

        $token = Socialite::driver($account->provider ?: 'google')
            ->stateless()
            ->refreshToken($account->refresh_token);

        $account->forceFill([
            'access_token' => $token->token,
            'refresh_token' => $token->refreshToken ?: $account->refresh_token,
            'token_expires_at' => now()->addSeconds((int) $token->expiresIn),
        ])->save();
    }

    private function normalizeEncryption(?string $encryption): string|false
    {
        return match ($encryption) {
            null, '', 'none', 'false' => false,
            default => $encryption,
        };
    }
}
