<?php

namespace Unico\Core\Mail\Contracts;

use Unico\Core\Models\IncomingEmail;

interface EmailClassifier
{
    /** Etichetta dell'email (es. `bounce`, `spam`, `complaint`) o null se non classificabile. */
    public function classify(IncomingEmail $email): ?string;
}
