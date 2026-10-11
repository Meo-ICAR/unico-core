<?php

namespace Unico\Core\Access;

/**
 * Per i ruoli di sola lettura (l'ispettore) le azioni Filament «libere» (quelle create con Action::make() / BulkAction::make() e
 * un nome loro, senza autorizzazione) spariscono, salvo quelle dell'elenco `unico-core.access.readonly_actions`. Le azioni standard
 * (crea, modifica, elimina...) seguono già i permessi della risorsa, che per l'ispettore sono di sola lettura.
 */
class ReadOnlyActions
{
    /** Azioni con un'autorizzazione di default legata alla risorsa: non si toccano. */
    private const STANDARD = ['create', 'edit', 'delete', 'view', 'replicate', 'forceDelete', 'restore', 'attach', 'detach', 'associate', 'dissociate'];

    public static function register(): void
    {
        foreach (['Filament\\Actions\\Action', 'Filament\\Actions\\BulkAction'] as $class) {
            if (class_exists($class)) {
                $class::configureUsing(function ($action) use ($class): void {
                    // Solo le classi base: EditAction, DeleteAction... hanno già la loro autorizzazione.
                    if ($action::class !== $class || in_array($action->getName(), self::STANDARD, true)) {
                        return;
                    }

                    $action->authorize(fn (): bool => self::allows((string) $action->getName(), app(AccessChecker::class)->isReadOnly()));
                });
            }
        }
    }

    public static function allows(string $actionName, bool $readOnly): bool
    {
        return ! $readOnly || in_array($actionName, (array) config('unico-core.access.readonly_actions', ['view', 'download', 'preview', 'print', 'open']), true);
    }
}
