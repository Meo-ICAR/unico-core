<?php

namespace Unico\Core\Console;

use Illuminate\Console\Command;
use Unico\Core\UnicoCoreServiceProvider;

class MigrateCoreCommand extends Command
{
    protected $signature = 'unico-core:migrate
                            {--rollback : Annulla l\'ultimo batch di migration core}
                            {--status : Mostra lo stato delle migration core}
                            {--pretend : Mostra le query senza eseguirle}
                            {--force : Esegue anche in produzione}';

    protected $description = 'Esegue le migration delle tabelle condivise sul database core';

    public function handle(): int
    {
        $this->useCoreMigrationsTable();

        $command = match (true) {
            $this->option('rollback') => 'migrate:rollback',
            $this->option('status') => 'migrate:status',
            default => 'migrate',
        };

        $arguments = [
            '--database' => config('unico-core.connection'),
            '--path' => UnicoCoreServiceProvider::migrationsPath(),
            '--realpath' => true,
        ];

        if ($command !== 'migrate:status') {
            $arguments['--force'] = (bool) $this->option('force');
            $arguments['--pretend'] = (bool) $this->option('pretend');
        }

        return $this->call($command, $arguments);
    }

    /**
     * Registra le migration core in una tabella separata da quella dell'app.
     */
    private function useCoreMigrationsTable(): void
    {
        $migrations = config('database.migrations');
        $table = config('unico-core.migrations_table');

        config(['database.migrations' => is_array($migrations)
            ? array_merge($migrations, ['table' => $table])
            : $table]);

        $this->laravel->forgetInstance('migration.repository');
        $this->laravel->forgetInstance('migrator');
    }
}
