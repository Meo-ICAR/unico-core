<?php

namespace Unico\Core\Console;

use Illuminate\Console\Command;
use Unico\Core\Documents\ConcludedPracticeExpiry;

class DocumentsClearConcludedExpiryCommand extends Command
{
    protected $signature = 'unico-core:documents-clear-concluded';

    protected $description = 'Azzera le scadenze e mette N/A ai documenti richiesti delle pratiche non più in lavorazione e dei loro clienti';

    public function handle(ConcludedPracticeExpiry $expiry): int
    {
        $fatti = $expiry->clearAll();
        $this->info("Scadenze azzerate: {$fatti['scadenze']} documenti; richiesti passati a N/A: {$fatti['richiesti']}.");

        return self::SUCCESS;
    }
}
