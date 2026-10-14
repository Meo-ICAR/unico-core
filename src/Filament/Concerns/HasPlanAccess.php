<?php

namespace Unico\Core\Filament\Concerns;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Piano della company e permessi EmployeeType sulle risorse Filament.
 * Le app lo riusano tramite App\Filament\Traits\HasPlanAccess.
 */
trait HasPlanAccess
{
    /**
     * Controlla la visibilità del menu di navigazione (per Resource e Page).
     */
    public static function shouldRegisterNavigation(): bool
    {
        if (static::navigationIsHidden()) {
            return false;
        }

        if (in_array(static::$navigationGroup, ['Settings', 'Configurazioni'], true) && ! \checkPiano('settings')) {
            return false;
        }

        return \checkPiano(static::getFeatureKey(), static::class);
    }

    /**
     * Controlla la visibilità delle schede/relazioni (per RelationManager).
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return \checkPiano(static::getFeatureKey(), static::class);
    }

    /**
     * Gate unico piano + ruolo, riusato da tutti i metodi di autorizzazione.
     *
     * Il controllo vale anche sull'accesso CRUD, non solo sulla voce di menu:
     * un ruolo senza la feature non apre la risorsa nemmeno con l'URL diretto.
     */
    protected static function hasPlanFeatureAccess(bool $write = false): bool
    {
        return \checkPiano(static::getFeatureKey(), static::class, $write);
    }

    public static function canViewAny(): bool
    {
        return static::hasPlanFeatureAccess();
    }

    public static function canView(Model $record): bool
    {
        return static::hasPlanFeatureAccess();
    }

    public static function canCreate(): bool
    {
        return static::hasPlanFeatureAccess(write: true);
    }

    public static function canEdit(Model $record): bool
    {
        return static::hasPlanFeatureAccess(write: true);
    }

    public static function canDelete(Model $record): bool
    {
        return static::hasPlanFeatureAccess(write: true);
    }

    public static function canDeleteAny(): bool
    {
        return static::hasPlanFeatureAccess(write: true);
    }

    public static function canForceDelete(Model $record): bool
    {
        return static::hasPlanFeatureAccess(write: true);
    }

    public static function canRestore(Model $record): bool
    {
        return static::hasPlanFeatureAccess(write: true);
    }

    /**
     * Chiave della feature: proprietà esplicita, relazione, slug Filament, oppure il nome della classe.
     */
    protected static function getFeatureKey(): string
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

        if (method_exists(static::class, 'getSlug')) {
            return static::getSlug();
        }

        return Str::kebab(class_basename(static::class));
    }

    /**
     * `$shouldRegisterNavigation = false` sulla risorsa resta vincolante:
     * il controllo del piano non rimette in menu una voce nascosta di proposito.
     */
    protected static function navigationIsHidden(): bool
    {
        $property = new \ReflectionClass(static::class)->getProperty('shouldRegisterNavigation');

        if ($property->getDeclaringClass()->getName() === \Filament\Resources\Resource::class) {
            return false;
        }

        return $property->getValue() === false;
    }
}
