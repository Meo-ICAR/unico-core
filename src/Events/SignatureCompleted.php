<?php

namespace Unico\Core\Events;

use Unico\Core\Models\Document;
use Unico\Core\Models\SignatureRequest;

/**
 * Tutti hanno firmato: il PDF firmato è nella collection `signed` del documento, che risulta `is_signed`.
 * Le app collegano qui i loro effetti (es. unicoloan approva il questionario di adeguata verifica).
 */
class SignatureCompleted
{
    public function __construct(public readonly SignatureRequest $request, public readonly Document $document) {}
}
