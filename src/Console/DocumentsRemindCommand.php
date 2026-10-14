<?php

namespace Unico\Core\Console;

use Illuminate\Console\Command;
use Unico\Core\Documents\Reminders\DocumentReminderService;
use Unico\Core\Documents\Reminders\ScheduleSynchronizer;

class DocumentsRemindCommand extends Command
{
    protected $signature = 'unico-core:documents-remind {--force : Invia anche se non è giornata di preavviso} {--sync : Ricalcola prima lo scadenziario}';

    protected $description = 'Invia i solleciti per i documenti in scadenza, raggruppati per soggetto';

    public function handle(DocumentReminderService $service, ScheduleSynchronizer $schedule): int
    {
        if ($this->option('sync')) {
            $this->info($schedule->sync().' righe nello scadenziario.');
        }

        $stats = $service->sendReminders(! $this->option('force'));

        $this->table(['Metrica', 'Valore'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all());

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
