<?php

namespace Unico\Core\Filament\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Enums\SignatureRequestStatus;
use Unico\Core\Filament\Actions\RetrySharePointUploadAction;
use Unico\Core\Filament\Actions\SendForSignatureAction;
use Unico\Core\Filament\Concerns\HasRelationPlanAccess;
use Unico\Core\Models\Client;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\Pratica;
use Unico\Core\Models\SignatureRequest;
use Unico\Core\Pdf\ModulePrinter;
use Unico\Core\Pdf\PdfFormException;
use Unico\Core\Pdf\PracticeModuleData;
use Unico\Core\Pdf\ResolvedModuleData;
use Unico\Core\Signature\Exceptions\SignatureRequestException;
use Unico\Core\Signature\SignatureRequestService;
use Unico\Core\ValueObjects\OamSemester;

/**
 * Documenti collegati al record: caricamento, plico, firma, rinnovo, stampa e download.
 * unicogdpr tiene il proprio gestore (Drive / merge).
 */
class DocumentsRelationManager extends RelationManager
{
    use HasRelationPlanAccess;

    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documenti';

    protected static ?string $modelLabel = 'Documento';

    protected static ?string $pluralModelLabel = 'Documenti';

    /**
     * @return Collection<int, Model>
     */
    private function applicablePlichi(): Collection
    {
        $taskClass = $this->plicoTaskClass();

        if ($taskClass === null) {
            return collect();
        }

        return $taskClass::getAvailableFor($this->getOwnerRecord())->load('documentTypes')->values();
    }

    private function plicoTaskClass(): ?string
    {
        foreach (array_filter([
            config('unico-core.documents.task_model'),
            class_exists(\App\Models\Task::class) ? \App\Models\Task::class : null,
        ]) as $class) {
            if (is_string($class) && class_exists($class) && method_exists($class, 'getAvailableFor')) {
                return $class;
            }
        }

        return null;
    }

    private function ownerTypeFlag(): ?string
    {
        $owner = $this->getOwnerRecord();
        $alias = method_exists($owner, 'getMorphClass') ? $owner->getMorphClass() : strtolower(class_basename($owner));

        return match ($alias) {
            'client' => 'is_client',
            'pratica' => 'is_practice',
            'cliente', 'clienti' => 'is_principal',
            'fornitore' => 'is_agent',
            'employee' => 'is_employee',
            'company' => 'is_company',
            default => match (true) {
                $owner instanceof Client => 'is_client',
                $owner instanceof Pratica => 'is_practice',
                default => null,
            },
        };
    }

    /**
     * @return array<int|string, string>
     */
    private function documentTypeOptions(bool $includeUsed = false, mixed $currentId = null, bool $excludeUsed = false): array
    {
        $flag = $this->ownerTypeFlag();

        return DocumentType::query()
            ->when($flag !== null, fn (Builder $query) => $query->where(function (Builder $query) use ($flag, $includeUsed, $currentId): void {
                $query->where($flag, true)
                    ->when($currentId, fn (Builder $query) => $query->orWhereKey($currentId))
                    ->when($includeUsed, fn (Builder $query) => $query->orWhereIn('id', $this->getOwnerRecord()->documents()->withTrashed()->select('document_type_id')));
            }))
            ->when($excludeUsed, fn (Builder $query) => $query
                ->whereNotIn('id', $this->getOwnerRecord()->documents()->whereNotNull('document_type_id')->select('document_type_id'))
                ->when($currentId, fn (Builder $query) => $query->orWhereKey($currentId)))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function exportAction(): ?Action
    {
        $exportClass = config('unico-core.documents.export_class')
            ?: (class_exists(\App\Filament\Exports\DynamicGroupExport::class) ? \App\Filament\Exports\DynamicGroupExport::class : null);

        if ($exportClass === null || ! class_exists(\pxlrbt\FilamentExcel\Actions\ExportAction::class)) {
            return null;
        }

        return \pxlrbt\FilamentExcel\Actions\ExportAction::make()
            ->exports([$exportClass::make()])
            ->label('Esporta Excel')
            ->color('success');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dettagli Documento')
                ->columnSpanFull()
                ->columns(2)
                ->components([
                    Select::make('document_type_id')
                        ->label('Tipo documento')
                        ->options(fn (?Document $record): array => $this->documentTypeOptions(currentId: $record?->document_type_id, excludeUsed: true))
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set, $get): void {
                            if (blank($get('name'))) {
                                $documentType = DocumentType::find($state);
                                $set('name', $documentType?->name);
                                $set('is_monitored', $documentType?->is_monitored);
                                $set('doctype', $documentType?->doctype);
                            }
                        })
                        ->columnSpanFull(),
                    TextInput::make('name')
                        ->label('Nome / Titolo')
                        ->default(fn ($get) => $get('document_type_id') ? DocumentType::find($get('document_type_id'))?->name : null)
                        ->required()
                        ->columnSpanFull(),
                    DatePicker::make('emitted_at')
                        ->label('Data emissione')
                        ->live()
                        ->displayFormat('d/m/y'),
                    Toggle::make('is_monitored')
                        ->label('Controlla scadenza')
                        ->default(fn ($get) => $get('document_type_id') ? (bool) DocumentType::find($get('document_type_id'))?->is_monitored : false)
                        ->live(),
                    DatePicker::make('expires_at')
                        ->label('Data scadenza')
                        ->default(fn ($get) => $get('document_type_id') ? DocumentType::find($get('document_type_id'))?->durationCalculate($get('emitted_at')) : null)
                        ->displayFormat('d/m/y')
                        ->visible(fn ($get) => $get('is_monitored'))
                        ->afterOrEqual('emitted_at'),
                    TextInput::make('docnumber')
                        ->label('Protocollo documento')
                        ->placeholder('es. CI-2024-001'),
                ]),
            Section::make('File Allegato')
                ->columnSpanFull()
                ->components([
                    TextInput::make('document_url')
                        ->label('URL documento')
                        ->url(),
                    SpatieMediaLibraryFileUpload::make('attachments')
                        ->label('Carica file (PDF, immagini, Word)')
                        ->multiple()
                        ->collection(Document::COLLECTION)
                        ->acceptedFileTypes(['application/pdf', 'image/*', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                        ->maxSize(20480)
                        ->columnSpanFull(),
                ]),
            Section::make('Plico')
                ->description('Il file contiene più documenti con scadenze diverse? Aggiungili qui: verranno creati con lo stesso allegato.')
                ->columnSpanFull()
                ->collapsed()
                ->hiddenOn('edit')
                ->components([
                    Repeater::make('plico')
                        ->hiddenLabel()
                        ->addActionLabel('Aggiungi documento al plico')
                        ->columns(3)
                        ->defaultItems(0)
                        ->components([
                            Select::make('document_type_id')
                                ->label('Tipo documento')
                                ->options(fn (): array => $this->documentTypeOptions())
                                ->searchable()
                                ->required(),
                            DatePicker::make('emitted_at')
                                ->label('Data emissione')
                                ->displayFormat('d/m/y')
                                ->required(),
                            TextInput::make('docnumber')
                                ->label('Protocollo documento'),
                        ]),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        $headerActions = array_values(array_filter([
            $this->plicoTaskClass() === null ? null : Action::make('generaPlico')
                ->label('Genera plico')
                ->icon('heroicon-o-rectangle-stack')
                ->color('gray')
                ->modalHeading('Genera plico documentale')
                ->modalDescription('Vengono creati, in stato "richiesto", i documenti dei plichi applicabili che non sono ancora presenti.')
                ->modalSubmitActionLabel('Genera')
                ->mountUsing(function (Action $action): void {
                    if ($this->applicablePlichi()->isEmpty()) {
                        Notification::make()->title('Nessun plico applicabile')->body('Per lo stato attuale di questo record non ci sono plichi documentali.')->warning()->send();
                        $action->cancel();
                    }
                })
                ->schema(fn (): array => [
                    CheckboxList::make('plichi')
                        ->label('Plichi')
                        ->options(fn (): array => $this->applicablePlichi()->mapWithKeys(fn (Model $task): array => [$task->getKey() => $task->name])->all())
                        ->descriptions(fn (): array => $this->applicablePlichi()->mapWithKeys(fn (Model $task): array => [
                            $task->getKey() => $task->documentTypes->count().' documenti ('.$task->documentTypes->filter(fn ($type) => $type->pivot->is_required)->count().' obbligatori)',
                        ])->all())
                        ->default(fn (): array => $this->applicablePlichi()->pluck('id')->all())
                        ->required()
                        ->columns(1),
                ])
                ->action(function (array $data): void {
                    $owner = $this->getOwnerRecord();
                    $created = 0;
                    $existing = 0;

                    foreach ($this->applicablePlichi()->whereIn('id', $data['plichi'] ?? []) as $task) {
                        $result = $task->generateDocumentsFor($owner);
                        $created += $result['created'];
                        $existing += $result['existing'];
                    }

                    Notification::make()
                        ->title($created.' documenti richiesti creati')
                        ->body($existing > 0 ? $existing.' erano già presenti.' : null)
                        ->success()
                        ->send();
                }),
            CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $owner = $this->getOwnerRecord();
                    $data['company_id'] = is_numeric($owner->company_id ?? null)
                        ? (int) $owner->company_id
                        : ($owner->getKey());

                    return $data;
                })
                ->after(function (CreateAction $action, Document $record): void {
                    $bundleRows = array_values($action->getData()['plico'] ?? []);

                    if ($bundleRows !== []) {
                        $record->createBundleCopies($bundleRows);
                    }
                }),
            $this->exportAction(),
        ]));

        $filters = [
            SelectFilter::make('document_type_id')
                ->label('Tipo documento')
                ->options(fn (): array => $this->documentTypeOptions(includeUsed: true))
                ->searchable(),
            SelectFilter::make('status')
                ->label('Stato')
                ->multiple()
                ->options(DocumentStatus::class),
            SelectFilter::make('doctype')
                ->label('Tipo documento')
                ->multiple()
                ->options([
                    'modulo' => 'Modulo',
                    'procedura' => 'Procedura',
                    'informativa' => 'Informativa',
                    'template' => 'Template',
                ]),
            Filter::make('is_monitored')
                ->label('Monitorato')
                ->query(fn ($query) => $query->where('is_monitored', true)),
            TernaryFilter::make('is_expired')
                ->label('Scaduto')
                ->default(false)
                ->queries(
                    true: fn ($query) => $query->where('status', DocumentStatus::EXPIRED->value),
                    false: fn ($query) => $query->where('status', '!=', DocumentStatus::EXPIRED->value),
                ),
            TrashedFilter::make(),
        ];

        if ($this->plicoTaskClass() !== null) {
            array_unshift($filters, Filter::make('plico_attuale')
                ->label('Plico dello stato attuale')
                ->toggle()
                ->query(fn (Builder $query) => $query->whereIn(
                    'document_type_id',
                    $this->applicablePlichi()->flatMap(fn (Model $task) => $task->documentTypes->pluck('id'))->unique()->values()->all(),
                )));
        }

        if (config('unico-core.documents.oam_semester_filter')) {
            array_unshift($filters, Filter::make('semestre_attuale')
                ->label('Solo semestre in corso')
                ->toggle()
                ->default(true)
                ->query(fn (Builder $query, array $data): Builder => $data['isActive']
                    ? $query->perSemestreOam(OamSemester::getInBaseAlMeseCorrente())
                    : $query));
        }

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])->with(['signatureRequests', 'media', 'documentType.pdfModule']))
            ->defaultSort('expires_at', 'desc')
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Documento')
                    ->searchable()
                    ->sortable()
                    ->default('Senza documento')
                    ->url(fn (Document $record): ?string => Route::has('documents.download') ? $record->download_url : null, shouldOpenInNewTab: true)
                    ->color(fn (Document $record): ?string => (Route::has('documents.download') && $record->download_url) ? 'info' : null),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->sortable(),
                TextColumn::make('emitted_at')
                    ->label('Emissione')
                    ->date('d/m/y')
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label('Scadenza')
                    ->date('d/m/y')
                    ->sortable()
                    ->color(fn ($record) => $record?->expires_at?->isPast() ? 'danger' : 'gray')
                    ->weight(fn ($record) => $record?->expires_at?->isPast() ? 'bold' : 'normal'),
                TextColumn::make('signature_state')
                    ->label('Firma')
                    ->badge()
                    ->state(fn (Document $record): string => self::signatureState($record))
                    ->color(fn (string $state): string => match ($state) {
                        'In attesa di firma' => 'info',
                        'Firmato' => 'success',
                        'Rifiutata', 'Scaduta' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('otp')
                    ->label('OTP')
                    ->badge()
                    ->state(fn (Document $record): ?string => self::otpState($record))
                    ->color(fn (?string $state): string => $state === 'Invia OTP' ? 'primary' : 'info')
                    ->icon(fn (?string $state): ?string => $state === 'Invia OTP' ? 'heroicon-o-device-phone-mobile' : null)
                    ->action(SendForSignatureAction::make(
                        onlyForSignedTypes: true,
                        preflight: fn (Document $document): ?string => self::missingSignerContacts($document),
                    )),
                TextColumn::make('doctype')
                    ->sortable()
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'modulo' => 'info',
                        'procedura' => 'warning',
                        'template' => 'success',
                        default => 'gray',
                    })
                    ->toggleable(),
            ])
            ->filters($filters)
            ->headerActions($headerActions)
            ->recordActions([
                EditAction::make(),
                RetrySharePointUploadAction::make(),
                Action::make('refreshSignature')
                    ->label('Aggiorna stato')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Document $record): bool => self::openSignatureRequest($record) !== null)
                    ->action(function (Document $record): void {
                        $request = self::openSignatureRequest($record);

                        if ($request === null) {
                            return;
                        }

                        try {
                            $request = app(SignatureRequestService::class)->reconcile($request);
                        } catch (SignatureRequestException $e) {
                            Notification::make()->danger()->title('Aggiornamento non riuscito')->body($e->getMessage())->send();

                            return;
                        }

                        if ($request->status === SignatureRequestStatus::Signed) {
                            Notification::make()->success()->title('Documento firmato')->send();
                        } elseif ($request->status->isOpen()) {
                            Notification::make()->info()->title('Ancora in attesa di firma')->send();
                        } else {
                            Notification::make()->warning()->title('Richiesta di firma: '.mb_strtolower($request->status->getLabel()))->send();
                        }
                    }),
                Action::make('cancelSignature')
                    ->label('Annulla richiesta di firma')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Document $record): bool => self::openSignatureRequest($record) !== null)
                    ->action(function (Document $record): void {
                        $request = self::openSignatureRequest($record);

                        if ($request === null) {
                            return;
                        }

                        try {
                            app(SignatureRequestService::class)->cancel($request, auth()->user());
                        } catch (SignatureRequestException $e) {
                            Notification::make()->danger()->title('Annullamento non riuscito')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Richiesta di firma annullata')->send();
                    }),
                Action::make('downloadDocument')
                    ->label('Scarica')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Document $record): bool => ! $record->is_signed && self::downloadKind($record) !== null)
                    ->action(fn (Document $record) => self::download($record)),
                Action::make('downloadSigned')
                    ->label('Scarica firmato')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Document $record): bool => $record->is_signed && $record->getFirstMedia(Document::COLLECTION) !== null)
                    ->action(function (Document $record) {
                        $media = $record->getFirstMedia(Document::COLLECTION);

                        return $media === null ? null : response()->download($media->getPath(), $media->file_name);
                    }),
                Action::make('stampa')
                    ->label('Stampa')
                    ->icon('heroicon-o-printer')
                    ->visible(fn (Document $record): bool => self::downloadKind($record) === 'module')
                    ->action(fn (Document $record) => self::download($record)),
                Action::make('scarica')
                    ->label('Scarica file')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Document $record): bool => $record->getFirstMedia(Document::COLLECTION) !== null && self::downloadKind($record) === null && ! $record->is_signed)
                    ->action(function (Document $record) {
                        $media = $record->getFirstMedia(Document::COLLECTION);

                        return $media === null ? null : response()->download($media->getPath(), $media->file_name);
                    }),
                Action::make('renew')
                    ->label('Rinnova')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->modalHeading(fn (Document $record) => "Rinnova documento: {$record->name}")
                    ->form([
                        DatePicker::make('emitted_at')
                            ->label('Nuova data di emissione')
                            ->default(now())
                            ->maxDate(now())
                            ->required(),
                        FileUpload::make('new_attachment')
                            ->label('Nuovo allegato (opzionale)')
                            ->helperText('Se carichi un file, viene creata una nuova versione del documento con il nuovo allegato; il documento attuale viene conservato come versione precedente con stato "scaduto".')
                            ->disk('public')
                            ->directory('document-renewals')
                            ->acceptedFileTypes(['application/pdf', 'image/*', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                            ->maxSize(20480),
                    ])
                    ->action(function (Document $record, array $data): void {
                        $newDocument = $record->renew($data['emitted_at']);

                        if (filled($data['new_attachment'] ?? null)) {
                            $newDocument->addMediaFromDisk($data['new_attachment'], 'public')
                                ->toMediaCollection(Document::COLLECTION);
                            Storage::disk('public')->delete($data['new_attachment']);
                        }

                        Notification::make()
                            ->title('Documento rinnovato')
                            ->body("Nuova versione generata con successo per \"{$record->name}\".")
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('setEmittedAt')
                        ->label('Imposta Data Emissione')
                        ->icon('heroicon-o-calendar')
                        ->color('success')
                        ->form([
                            DatePicker::make('emitted_at')
                                ->label('Data di Emissione')
                                ->required()
                                ->default(now()),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->each(fn ($record) => $record->update([
                                'emitted_at' => $data['emitted_at'] ?? now(),
                            ]));
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->modalHeading('Imposta data di emissione per i record selezionati')
                        ->modalSubmitActionLabel('Salva'),
                    BulkAction::make('setExpiredAt')
                        ->label('Imposta Data Scadenza')
                        ->icon('heroicon-o-calendar')
                        ->color('success')
                        ->form([
                            DatePicker::make('expired_at')
                                ->label('Data di Scadenza')
                                ->required()
                                ->default(now()),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->each(fn ($record) => $record->update([
                                'expires_at' => $data['expired_at'],
                            ]));
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->modalHeading('Imposta data di scadenza per i record selezionati')
                        ->modalSubmitActionLabel('Salva'),
                ]),
            ]);
    }

    private static function downloadKind(Document $document): ?string
    {
        $type = $document->documentType;

        if ($type === null) {
            return null;
        }

        if ($type->pdfModule?->is_active && self::canPrintModule($document)) {
            return 'module';
        }

        if (($type->is_template || $document->is_template) && ($document->getFirstMedia(Document::COLLECTION) !== null || filled($document->document_url))) {
            return 'template';
        }

        return null;
    }

    private static function moduleDataFor(Document $document): ?ResolvedModuleData
    {
        $owner = $document->documentable;

        if (class_exists(\App\Services\ModuleDataResolver::class)) {
            $resolver = app(\App\Services\ModuleDataResolver::class);

            if ($owner instanceof Client) {
                return $resolver->resolveForClient($owner);
            }

            if ($owner instanceof Pratica) {
                $client = $resolver->findClient($owner);

                return $client === null ? null : $resolver->resolve($owner, $client);
            }
        }

        return $owner instanceof Pratica ? app(PracticeModuleData::class)->for($owner) : null;
    }

    private static function canPrintModule(Document $document): bool
    {
        $owner = $document->documentable;
        $type = $document->documentType;

        return match (true) {
            $owner instanceof Client => (bool) $type?->is_client,
            $owner instanceof Pratica => (bool) $type?->is_practice && (
                class_exists(\App\Services\ModuleDataResolver::class)
                    ? app(\App\Services\ModuleDataResolver::class)->findClient($owner) !== null
                    : app(PracticeModuleData::class)->findClient($owner) !== null
            ),
            default => false,
        };
    }

    private static function download(Document $document): mixed
    {
        if (self::downloadKind($document) === 'module') {
            $module = $document->documentType->pdfModule;
            $data = self::moduleDataFor($document);

            try {
                $content = $data === null
                    ? app(ModulePrinter::class)->print($document->documentable, $module)
                    : app(ModulePrinter::class)->render($module, $data);
            } catch (PdfFormException $e) {
                report($e);
                Notification::make()->danger()->title('Stampa non disponibile')->body($e->getMessage())->send();

                return null;
            }

            return response()->streamDownload(fn () => print ($content), Str::slug($module->name).'.pdf', ['Content-Type' => 'application/pdf']);
        }

        $media = $document->getFirstMedia(Document::COLLECTION);

        if ($media !== null) {
            return response()->download($media->getPath(), $media->file_name);
        }

        return filled($document->document_url) ? redirect()->away($document->document_url) : null;
    }

    private static function openSignatureRequest(Document $document): ?SignatureRequest
    {
        $request = $document->latestSignatureRequest();

        return $request?->isOpen() ? $request : null;
    }

    private static function otpState(Document $document): ?string
    {
        if ($document->is_signed || ! $document->documentType?->is_signed) {
            return null;
        }

        return self::openSignatureRequest($document) !== null ? 'OTP inviato' : 'Invia OTP';
    }

    private static function missingSignerContacts(Document $document): ?string
    {
        $owner = $document->documentable;
        $client = $owner instanceof Pratica
            ? (class_exists(\App\Services\ModuleDataResolver::class)
                ? app(\App\Services\ModuleDataResolver::class)->findClient($owner)
                : app(PracticeModuleData::class)->findClient($owner))
            : $owner;

        if (! $client instanceof Client) {
            return null;
        }

        $missing = collect(['phone' => 'cellulare', 'email' => 'email'])
            ->filter(fn (string $label, string $field): bool => blank($client->{$field}))
            ->values();

        return $missing->isEmpty()
            ? null
            : 'Mancano '.$missing->implode(' ed ').' del cliente: completare l\'anagrafica prima di inviare l\'OTP.';
    }

    private static function signatureState(Document $document): string
    {
        if ($document->is_signed) {
            return 'Firmato';
        }

        return match ($document->latestSignatureRequest()?->status) {
            SignatureRequestStatus::Pending, SignatureRequestStatus::Sent => 'In attesa di firma',
            SignatureRequestStatus::Declined => 'Rifiutata',
            SignatureRequestStatus::Expired => 'Scaduta',
            SignatureRequestStatus::Cancelled => 'Annullata',
            SignatureRequestStatus::Failed => 'Non riuscita',
            default => 'Non firmato',
        };
    }
}
