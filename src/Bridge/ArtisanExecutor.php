<?php

namespace Unico\Core\Bridge;

use Illuminate\Support\Facades\Artisan;

/** Esegue un comando Artisan e ne restituisce codice di uscita e output (separato dal job per poterlo sostituire nei test). */
class ArtisanExecutor
{
    /**
     * @param  array<string, mixed>  $options  già nella forma `--nome` => valore
     * @return array{0: int, 1: string}
     */
    public function run(string $command, array $options): array
    {
        $exitCode = Artisan::call($command, $options);

        return [$exitCode, (string) Artisan::output()];
    }
}
