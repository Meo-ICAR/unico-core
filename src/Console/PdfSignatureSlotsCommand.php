<?php

namespace Unico\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Unico\Core\Models\PdfModule;
use Unico\Core\Pdf\SignatureAnchors;

class PdfSignatureSlotsCommand extends Command
{
    protected $signature = 'unico-core:pdf-slots {module? : ID del modulo; senza, tutti} {--force : Sovrascrive gli slot già impostati} {--dry-run : Mostra gli slot senza salvarli}';

    protected $description = 'Imposta i punti di firma dei moduli PDF leggendo i segnaposto {{Sig1_ev_:signer1:signature:...}} nel testo';

    public function handle(SignatureAnchors $anchors): int
    {
        $disk = Storage::disk(config('unico-core.pdf.modules_disk', 'public'));
        $modules = PdfModule::query()->when($this->argument('module'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get();

        foreach ($modules as $module) {
            if (! $disk->exists($module->file_path)) {
                $this->warn("{$module->name}: file non trovato.");

                continue;
            }

            $slots = $anchors->scan($disk->path($module->file_path), (string) config('unico-core.pdf.pdftotext_binary', 'pdftotext'));

            if ($slots === []) {
                $this->line("{$module->name}: nessun segnaposto di firma.");

                continue;
            }

            if (! empty($module->signature_slots) && ! $this->option('force')) {
                $this->line("{$module->name}: slot già impostati (usa --force per sostituirli).");

                continue;
            }

            if (! $this->option('dry-run')) {
                $module->update(['signature_slots' => $slots]);
            }

            $this->info(sprintf('%s: %d punti di firma (%s).', $module->name, count($slots), implode(', ', array_unique(array_column($slots, 'slot')))));
        }

        return self::SUCCESS;
    }
}
