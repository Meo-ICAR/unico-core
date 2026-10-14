<?php

namespace Unico\Core\Pdf;

use Illuminate\Support\Collection;
use Unico\Core\Models\PdfModule;

/** Moduli PDF attivi pertinenti per prodotto e tipo di cliente, da stampare. */
class ModuleSuggester
{
    /**
     * @return Collection<int, PdfModule>
     */
    public function suggest(?string $tipoProdotto, bool $isPerson): Collection
    {
        return $this->matching(
            PdfModule::query()->active()->orderBy('name')->get(),
            $tipoProdotto,
            $isPerson,
        );
    }

    /**
     * @param  iterable<PdfModule>  $modules
     * @return Collection<int, PdfModule>
     */
    public function matching(iterable $modules, ?string $tipoProdotto, bool $isPerson): Collection
    {
        return collect($modules)
            ->filter(fn (PdfModule $module) => $module->appliesTo($tipoProdotto, $isPerson))
            ->values();
    }
}
