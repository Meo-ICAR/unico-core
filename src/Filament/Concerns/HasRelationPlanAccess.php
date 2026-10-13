<?php

namespace Unico\Core\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Visibilità delle schede RelationManager in base al piano e ai permessi EmployeeType.
 * Le app lo riusano tramite App\Filament\Traits\HasRelationPlanAccess.
 */
trait HasRelationPlanAccess
{
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return \checkPiano(static::getFeatureKey(), static::class);
    }

    /** I ruoli di sola lettura (l'ispettore) vedono la scheda ma non possono creare, modificare o eliminare. */
    public function isReadOnly(): bool
    {
        return parent::isReadOnly() || ! \checkPiano(static::getFeatureKey(), static::class, write: true);
    }

    public static function getFeatureKey(): string
    {
        if (property_exists(static::class, 'featureKey') && static::$featureKey !== null) {
            return static::$featureKey;
        }

        if (method_exists(static::class, 'getRelationshipName')) {
            return static::getRelationshipName();
        }

        if (property_exists(static::class, 'relationship') && static::$relationship !== null) {
            return static::$relationship;
        }

        return Str::kebab(class_basename(static::class));
    }
}
