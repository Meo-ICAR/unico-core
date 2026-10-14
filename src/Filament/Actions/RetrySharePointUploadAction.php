<?php

namespace Unico\Core\Filament\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Unico\Core\Enums\SyncStatus;
use Unico\Core\Models\Document;
use Unico\Core\SharePoint\Jobs\UploadDocumentToSharePoint;
use Unico\Core\SharePoint\SharePointUploader;

/** Rimette in coda l'upload su SharePoint di un documento fallito o rimasto solo locale. */
class RetrySharePointUploadAction
{
    public static function make(): Action
    {
        return Action::make('retrySharePointUpload')
            ->label('Riprova upload')
            ->icon('heroicon-o-cloud-arrow-up')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (Document $record): bool => in_array($record->sync_status, [SyncStatus::FAILED->value, SyncStatus::LOCAL->value], true)
                && app(SharePointUploader::class)->isConfigured()
                && $record->getFirstMedia(Document::COLLECTION) !== null)
            ->action(function (Document $record): void {
                UploadDocumentToSharePoint::dispatch($record);

                Notification::make()
                    ->title('Upload rimesso in coda')
                    ->success()
                    ->send();
            });
    }
}
