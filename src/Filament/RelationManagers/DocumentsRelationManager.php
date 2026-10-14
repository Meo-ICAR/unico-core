<?php

namespace Unico\Core\Filament\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Filament\Concerns\HasRelationPlanAccess;
use Unico\Core\Models\Document;
use Unico\Core\Models\Pratica;
use Unico\Core\Pdf\ModulePrinter;
use Unico\Core\Pdf\PdfFormException;

/**
 * Documenti collegati al record: caricamento, stampa del modulo PDF e download del file.
 * Le app lo agganciano alle risorse Filament; unicogdpr tiene il proprio gestore.
 */
class DocumentsRelationManager extends RelationManager
{
    use HasRelationPlanAccess;

    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documenti';

    protected static ?string $modelLabel = 'Documento';

    protected static ?string $pluralModelLabel = 'Documenti';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Documento')
                ->columnSpanFull()
                ->columns(2)
                ->components([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->columnSpanFull(),
                    TextInput::make('docnumber')
                        ->label('Protocollo'),
                    DatePicker::make('emitted_at')
                        ->label('Data emissione')
                        ->native(false)
                        ->displayFormat('d/m/Y'),
                ]),
            Section::make('File')
                ->columnSpanFull()
                ->components([
                    SpatieMediaLibraryFileUpload::make('attachments')
                        ->label('Carica file (PDF o immagine)')
                        ->collection(Document::COLLECTION)
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(20480)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Documento')
                    ->searchable(),
                TextColumn::make('documentType.name')
                    ->label('Tipo')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge(),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Carica')
                    ->after(function (Document $record): void {
                        if ($record->status === DocumentStatus::PENDING && $record->getFirstMedia(Document::COLLECTION) !== null) {
                            $record->update(['status' => DocumentStatus::UPLOADED]);
                        }
                    }),
                Action::make('stampa')
                    ->label('Stampa')
                    ->icon('heroicon-o-printer')
                    ->visible(fn (Document $record): bool => $this->getOwnerRecord() instanceof Pratica && (bool) $record->documentType?->pdfModule?->is_active)
                    ->action(fn (Document $record) => $this->printModule($record)),
                Action::make('scarica')
                    ->label('Scarica')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Document $record): bool => $record->getFirstMedia(Document::COLLECTION) !== null)
                    ->action(function (Document $record) {
                        $media = $record->getFirstMedia(Document::COLLECTION);

                        return $media === null ? null : response()->download($media->getPath(), $media->file_name);
                    }),
            ]);
    }

    private function printModule(Document $document): mixed
    {
        $module = $document->documentType?->pdfModule;
        $owner = $this->getOwnerRecord();

        if ($module === null || ! $module->is_active || ! $owner instanceof Pratica) {
            return null;
        }

        try {
            $content = app(ModulePrinter::class)->print($owner, $module);
        } catch (PdfFormException $e) {
            report($e);
            Notification::make()->danger()->title('Stampa non disponibile')->body($e->getMessage())->send();

            return null;
        }

        return response()->streamDownload(
            fn () => print ($content),
            Str::slug($module->name).'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }
}
