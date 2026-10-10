<?php

namespace Unico\Core\Events;

use Illuminate\Support\Collection;
use Unico\Core\Models\MailAccount;

/** Emesso dal controllo di salute quando ci sono caselle ferme o con token da rinnovare. */
class MailAccountsUnhealthy
{
    /**
     * @param  Collection<int, MailAccount>  $stale  Caselle che non sincronizzano da troppo tempo
     * @param  Collection<int, MailAccount>  $expiringTokens  Caselle OAuth2 con token in scadenza e senza refresh token
     */
    public function __construct(public Collection $stale, public Collection $expiringTokens, public int $staleHours) {}
}
