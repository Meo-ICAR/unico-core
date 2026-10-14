<?php

namespace Unico\Core\Documents\Contracts;

use Unico\Core\Documents\CheckResult;
use Unico\Core\Documents\DocumentContext;

/** Controllo a plug-in eseguito sui dati letti da un documento. */
interface DocumentCheck
{
    public function inspect(DocumentContext $context): CheckResult;
}
