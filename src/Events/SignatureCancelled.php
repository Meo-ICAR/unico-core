<?php

namespace Unico\Core\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Unico\Core\Models\SignatureRequest;

/** Richiesta di firma annullata dall'operatore. */
class SignatureCancelled
{
    public function __construct(public readonly SignatureRequest $request, public readonly ?Authenticatable $user = null) {}
}
