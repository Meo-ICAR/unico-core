<?php

namespace Unico\Core;

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
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/unico-core.php' => config_path('unico-core.php'),
            ], 'unico-core-config');

            $this->commands([MigrateCoreCommand::class]);
        }
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
