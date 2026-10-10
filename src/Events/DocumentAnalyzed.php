<?php

namespace Unico\Core\Events;

use Unico\Core\Documents\PipelineResult;

/** Dispatchato a fine pipeline: le app aggiornano pratiche, notificano l'agente, propongono i dati letti. */
class DocumentAnalyzed
{
    public function __construct(public readonly PipelineResult $result) {}
}
