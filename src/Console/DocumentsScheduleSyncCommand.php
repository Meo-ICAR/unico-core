<?php

namespace Unico\Core\Console;

use Illuminate\Console\Command;
use Unico\Core\Documents\Reminders\ScheduleSynchronizer;

class DocumentsScheduleSyncCommand extends Command
{
    protected $signature = 'unico-core:documents-schedule-sync';

    protected $description = 'Ricalcola lo scadenziario dei documenti';

    public function handle(ScheduleSynchronizer $schedule): int
    {
        $this->info('Scadenziario sincronizzato: '.$schedule->sync().' righe.');

        return self::SUCCESS;
    }
}
