<?php

namespace Unico\Core\Console;

use Illuminate\Console\Command;
use Unico\Core\Documents\DocumentTypeConsistency;

class DocumentTypesCheckCommand extends Command
{
    protected $signature = 'unico-core:document-types-check {--summary : Solo il conteggio per problema}';

    protected $description = 'Controlla la coerenza dei tipi documento (fonte, servizio API, modulo PDF, template, firma, scadenza)';

    public function handle(DocumentTypeConsistency $consistency): int
    {
        $problems = $consistency->check();

        if ($problems === []) {
            $this->info('Nessun problema.');

            return self::SUCCESS;
        }

        if ($this->option('summary')) {
            $this->table(['Problema', 'Tipi'], collect($problems)->countBy('problem')->sortDesc()->map(fn (int $count, string $problem): array => [$problem, $count])->values()->all());
        } else {
            $this->table(['ID', 'Tipo documento', 'Problema'], array_map(fn (array $row): array => [$row['id'], $row['name'], $row['problem']], $problems));
        }

        $this->warn(count($problems).' problemi su '.collect($problems)->pluck('id')->unique()->count().' tipi documento.');

        return self::FAILURE;
    }
}
