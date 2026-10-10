<?php

namespace Unico\Core\Documents\Checks;

use Unico\Core\Documents\CheckResult;
use Unico\Core\Documents\Contracts\DocumentCheck;
use Unico\Core\Documents\DocumentContext;

/** Codice `leggibile`: la lettura AI deve aver trovato i campi principali. */
class ReadableCheck implements DocumentCheck
{
    public function inspect(DocumentContext $context): CheckResult
    {
        return ($context->fields()['legible'] ?? true)
            ? CheckResult::pass()
            : CheckResult::fail('Il documento non è leggibile: serve una foto più nitida, senza riflessi e con tutto il documento inquadrato.');
    }
}
