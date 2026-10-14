<?php

namespace Unico\Core\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Per le classi che estendono pxlrbt\FilamentExcel\Exports\ExcelExport.
 *
 * filament-excel inietta il record solo per nome ($record), ma i formatter di
 * Filament (colori, money, date, numerici) tipizzano `?Model $relatedRecord`:
 * il container prova a istanziare Model e fallisce con
 * "Target [Illuminate\Database\Eloquent\Model] is not instantiable".
 * Qui il record viene iniettato anche per tipo.
 */
trait InjectsRecordByTypeInExports
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function evaluate(mixed $value, array $parameters = []): mixed
    {
        $record = $parameters['record'] ?? null;

        $typedInjections = $record instanceof Model
            ? [Model::class => $record, $record::class => $record]
            : [];

        return $this->parentEvaluate($value, [
            ...$parameters,
            ...$this->getDefaultEvaluationParameters(),
        ], $typedInjections);
    }
}
