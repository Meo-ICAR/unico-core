<?php

namespace Unico\Core\Filament\Actions;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Models\Document;

/**
 * Esito dell'istruttoria su un documento: OK, Respinto, Anomalo, N/A, Integrativo (richiesto in più dalla banca).
 * Per gli esiti negativi o da integrare la nota è obbligatoria e resta sul documento (`rejection_note`); chi decide e quando
 * si registra in `verified_by` e `verified_at`.
 */
class DocumentOutcomeAction
{
    public static function make(): Action
    {
        return Action::make('esitoIstruttoria')
            ->label('Esito')
            ->icon('heroicon-o-clipboard-document-check')
            ->modalHeading('Esito dell\'istruttoria')
            ->modalSubmitActionLabel('Salva')
            ->fillForm(fn (Document $record): array => ['status' => $record->status?->value, 'nota' => $record->rejection_note])
            ->schema(self::fields())
            ->action(function (Document $record, array $data): void {
                self::apply($record, $data);
                Notification::make()->title('Esito registrato')->success()->send();
            });
    }

    public static function bulk(): BulkAction
    {
        return BulkAction::make('esitoIstruttoriaMassivo')
            ->label('Esito dell\'istruttoria')
            ->icon('heroicon-o-clipboard-document-check')
            ->modalHeading('Esito sui documenti selezionati')
            ->schema(self::fields())
            ->action(function (Collection $records, array $data): void {
                $records->each(fn (Document $document) => self::apply($document, $data));
                Notification::make()->title($records->count().' documenti aggiornati')->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /** @return array<int, mixed> */
    private static function fields(): array
    {
        return [
            Select::make('status')->label('Esito')->options(DocumentStatus::istruttoria())->required()->live(),
            Textarea::make('nota')
                ->label('Nota')
                ->rows(3)
                ->maxLength(1000)
                ->required(fn (Get $get): bool => self::richiedeNota((string) $get('status'))),
        ];
    }

    private static function richiedeNota(string $status): bool
    {
        return in_array($status, [DocumentStatus::REJECTED->value, DocumentStatus::ANOMALY->value, DocumentStatus::SUPPLEMENTARY->value], true);
    }

    /** @param  array{status: string, nota?: ?string}  $data */
    private static function apply(Document $document, array $data): void
    {
        $document->forceFill([
            'status' => $data['status'],
            'rejection_note' => self::richiedeNota($data['status']) ? $data['nota'] : null,
            'verified_by' => auth()->id(),
            'verified_at' => now(),
        ])->save();
    }
}
