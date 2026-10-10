<?php

namespace Unico\Core;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Unico\Core\Console\MigrateCoreCommand;

class UnicoCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/unico-core.php', 'unico-core');
    }

    public function boot(): void
    {
        $this->registerDocumentsDisk();

        Relation::morphMap(MorphMap::all(), merge: true);
        if (config('unico-core.enforce_morph_map')) {
            Relation::requireMorphMap();
        }

        if (config('unico-core.routes.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/unico-core.php' => config_path('unico-core.php'),
            ], 'unico-core-config');

            $this->commands([MigrateCoreCommand::class]);
        }
    }

    /** Registra il disco privato dei documenti se l'app non lo definisce. */
    protected function registerDocumentsDisk(): void
    {
        $name = config('unico-core.documents_disk');

        if ($name === null || config("filesystems.disks.{$name}") !== null) {
            return;
        }

        config(["filesystems.disks.{$name}" => [
            'driver' => 'local',
            'root' => config('unico-core.documents_root') ?? storage_path('app/private/documents'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ]]);
    }

    /**
     * Percorso delle migration core. Non vengono caricate in automatico:
     * si eseguono solo con il comando unico-core:migrate.
     */
    public static function migrationsPath(): string
    {
        return realpath(__DIR__.'/../database/migrations');
    }
}
