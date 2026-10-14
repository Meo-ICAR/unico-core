<?php

namespace Unico\Core\Documents\Checks;

use Unico\Core\Documents\CheckResult;
use Unico\Core\Documents\Contracts\DocumentCheck;
use Unico\Core\Documents\DocumentContext;

/** Codice `informativa_firmata`: l'allegato deve essere il nostro modulo di informativa privacy e portare la firma del cliente. */
class InformativaFirmataCheck implements DocumentCheck
{
    public function inspect(DocumentContext $context): CheckResult
    {
        $fields = $context->fields() ?? [];

        if (($fields['matches_template'] ?? null) !== true) {
            return CheckResult::fail('Il file non sembra il nostro modulo di informativa privacy: scaricare il modulo, farlo firmare e caricarlo di nuovo.');
        }

        // Se la firma non si vede con certezza non si dà per firmata.
        if (($fields['signed'] ?? null) !== true) {
            return CheckResult::fail('L\'informativa non risulta firmata: far firmare il cliente nello spazio «Per presa visione» e caricare di nuovo il modulo.');
        }

        return CheckResult::pass();
    }
}
