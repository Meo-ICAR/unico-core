<?php

namespace Unico\Core\Console;

use Illuminate\Console\Command;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Models\Document;
use Unico\Core\Pdf\PrintReadiness;

/**
 * Ricalcola la fase dei moduli da firmare che non sono ancora partiti (richiesto, dati mancanti, da stampare): quando i dati della pratica
 * o del cliente si completano il documento passa da «dati mancanti» a «da stampare» senza che qualcuno apra la tabella.
 */
class DocumentsPrintRefreshCommand extends Command
{
    protected $signature = 'unico-core:documents-print-refresh';

    protected $description = 'Ricalcola la fase (dati mancanti / da stampare) dei moduli da firmare';

    public function handle(PrintReadiness $readiness): int
    {
        $controllati = 0;
        $cambiati = 0;

        Document::query()
            ->whereIn('status', array_map(fn (DocumentStatus $status): string => $status->value, [DocumentStatus::PENDING, DocumentStatus::MISSING_DATA, DocumentStatus::PRINT]))
            ->whereNull('renewed_by')
            ->whereHas('documentType', fn ($query) => $query->where('is_signed', true)->whereHas('pdfModule'))
            ->with(['documentType.pdfModule', 'documentable'])
            ->chunkById(200, function ($documenti) use ($readiness, &$controllati, &$cambiati): void {
                foreach ($documenti as $documento) {
                    $prima = $documento->status;
                    $readiness->refresh($documento);
                    $controllati++;
                    $cambiati += $documento->status !== $prima ? 1 : 0;
                }
            });

        $this->info("Moduli controllati: {$controllati}, fase cambiata: {$cambiati}.");

        return self::SUCCESS;
    }
}
