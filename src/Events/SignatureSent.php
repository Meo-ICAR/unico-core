<?php

namespace Unico\Core\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Unico\Core\Models\SignatureRequest;

/** Richiesta di firma inviata al provider. Le app registrano l'attività (es. activity log). */
class SignatureSent
{
    public function __construct(public readonly SignatureRequest $request, public readonly ?Authenticatable $user = null) {}
}
