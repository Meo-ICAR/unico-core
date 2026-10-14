<?php

namespace Unico\Core;

use Google\Service\Drive;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Unico\Core\Console\FetchMailCommand;
use Unico\Core\Console\MailHealthCommand;
use Unico\Core\Console\ImportAnagraficheCommand;
use Unico\Core\Console\MigrateCoreCommand;
use Unico\Core\Drive\GoogleDriveFactory;
use Unico\Core\Documents\AnthropicDocumentReader;
use Unico\Core\Documents\CheckRegistry;
use Unico\Core\Documents\Contracts\AnalysisGate;
use Unico\Core\Documents\Contracts\DeclaredData;
use Unico\Core\Documents\Contracts\DocumentFileSource;
use Unico\Core\Documents\Contracts\DocumentReader;
use Unico\Core\Documents\Listeners\AnalyzeUploadedDocument;
use Unico\Core\Documents\MediaDocumentFileSource;
use Unico\Core\Documents\NullDeclaredData;
use Unico\Core\Documents\NullDocumentReader;
use Unico\Core\Documents\OpenGate;
use Unico\Core\Documents\DocumentPathGenerator;
use Unico\Core\Models\Document;
use Unico\Core\Drive\ZipExporter;
use Unico\Core\SharePoint\GraphTransport;
use Unico\Core\SharePoint\HttpGraphTransport;
use Unico\Core\SharePoint\Listeners\QueueSharePointUpload;
use Unico\Core\Console\DocumentsRemindCommand;
use Unico\Core\Console\DocumentsScheduleSyncCommand;
use Unico\Core\Console\SharePointRetryCommand;
use Unico\Core\Console\SignatureCheckCommand;
use Unico\Core\Console\SignatureSyncCommand;
use Unico\Core\Documents\Reminders\ConfigRecipientResolver;
use Unico\Core\Documents\Reminders\Contracts\RecipientResolver;
use Unico\Core\Mail\Contracts\EmailClassifier;
use Unico\Core\Mail\Contracts\ImapConnector;
use Unico\Core\Mail\ImapConnectionFactory;
use Unico\Core\Mail\RuleBasedClassifier;
use Unico\Core\Mail\SenderIdentifier;
use Unico\Core\Signature\SignatureProviderManager;

class UnicoCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/unico-core.php', 'unico-core');

        $this->app->bindIf(ImapConnector::class, ImapConnectionFactory::class);
        $this->app->bindIf(EmailClassifier::class, RuleBasedClassifier::class);
        $this->app->singleton(SignatureProviderManager::class);
        $this->app->bind(SenderIdentifier::class, fn ($app) => new SenderIdentifier($app->tagged('unico-core.sender-matchers')));

        // Il client Drive si costruisce solo quando serve: le app senza credenziali Google non falliscono all'avvio.
        $this->app->bindIf(Drive::class, fn () => GoogleDriveFactory::make());
        $this->app->bindIf(GraphTransport::class, HttpGraphTransport::class);

        $this->app->bindIf(RecipientResolver::class, ConfigRecipientResolver::class);

        // Analisi documenti: ogni pezzo è sostituibile dall'app con un proprio bind.
        $this->app->singleton(CheckRegistry::class);
        $this->app->singleton(\Unico\Core\Access\AccessChecker::class);
        $this->app->bindIf(DocumentFileSource::class, MediaDocumentFileSource::class);
        $this->app->bindIf(DeclaredData::class, NullDeclaredData::class);
        $this->app->bindIf(AnalysisGate::class, OpenGate::class);
        $this->app->bindIf(DocumentReader::class, function () {
            $key = (string) config('unico-core.documents.ai.api_key');

            return $key === '' ? new NullDocumentReader : new AnthropicDocumentReader($key, (string) config('unico-core.documents.ai.model'));
        });
        $this->app->bind(ZipExporter::class, fn () => new ZipExporter(GoogleDriveFactory::make(readOnly: true)));
    }

    public function boot(): void
    {
        \Unico\Core\Access\ReadOnlyActions::register();

        $this->registerDocumentsDisk();

        Relation::morphMap(MorphMap::all(), merge: true);
        if (config('unico-core.enforce_morph_map')) {
            Relation::requireMorphMap();
        }

        if (config('unico-core.documents.readable_paths')) {
            config(['media-library.custom_path_generators' => array_merge(
                config('media-library.custom_path_generators', []),
                [Document::class => DocumentPathGenerator::class],
            )]);
        }

        if (config('unico-core.documents.analysis.auto') && class_exists(MediaHasBeenAddedEvent::class)) {
            Event::listen(MediaHasBeenAddedEvent::class, AnalyzeUploadedDocument::class);
        }

        if (config('unico-core.sharepoint.auto') && class_exists(MediaHasBeenAddedEvent::class)) {
            Event::listen(MediaHasBeenAddedEvent::class, QueueSharePointUpload::class);
        }

        if (config('unico-core.routes.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if (config('unico-core.bridge.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/bridge.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/unico-core.php' => config_path('unico-core.php'),
            ], 'unico-core-config');

            $this->commands([MigrateCoreCommand::class, ImportAnagraficheCommand::class, FetchMailCommand::class, MailHealthCommand::class, SharePointRetryCommand::class, DocumentsRemindCommand::class, DocumentsScheduleSyncCommand::class, SignatureSyncCommand::class, SignatureCheckCommand::class]);
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
