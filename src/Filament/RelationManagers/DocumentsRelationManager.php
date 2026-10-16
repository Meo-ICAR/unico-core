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
use Filament\Tables\Columns\IconColumn;
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
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Unico\Core\Enums\DocumentAnomaly;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Enums\SignatureRequestStatus;
use Unico\Core\Documents\PlicoArchive;
use Unico\Core\Documents\PracticeTaskDocuments;
use Unico\Core\Models\TipoProdottoSub;
use Unico\Core\Documents\Api\DocumentApiService;
use Unico\Core\Filament\Actions\DocumentOutcomeAction;
use Unico\Core\Filament\Actions\RetrySharePointUploadAction;
use Unico\Core\Filament\Actions\SendForSignatureAction;
use Unico\Core\Filament\Concerns\HasRelationPlanAccess;
use Unico\Core\Models\Client;
use Unico\Core\Models\Company;
use Unico\Core\MorphMap;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\Pratica;
use Unico\Core\Models\SignatureRequest;
use Unico\Core\Signature\SignerDefaults;
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

    /** Punto in cui è un documento richiesto dal fascicolo (task): icona e colore. */
    private const FASI_FASCICOLO = [
        'ok' => ['heroicon-m-check-circle', 'success'],
        'caricato' => ['heroicon-m-arrow-up-circle', 'info'],
        'da_firmare' => ['heroicon-m-pencil-square', 'warning'],
        'problema' => ['heroicon-m-exclamation-triangle', 'danger'],
        'mancante' => ['heroicon-m-exclamation-circle', 'danger'],
        'facoltativo' => ['heroicon-m-minus-circle', 'gray'],
    ];

    private const NOMI_FASI = [
        'ok' => 'completo e verificato',
        'caricato' => 'caricato, da verificare',
        'da_firmare' => 'da firmare',
        'problema' => 'da correggere (respinto, anomalo o integrativo)',
        'mancante' => 'mancante',
        'facoltativo' => 'facoltativo, non presente',
    ];

    /** Esito dei controlli (verifica tecnica e lettura AI): icona e colore. */
    private const ESITI_CONTROLLI = [
        'passed' => ['heroicon-m-check-badge', 'success'],
        'needs_review' => ['heroicon-m-eye', 'warning'],
        'failed' => ['heroicon-m-exclamation-triangle', 'danger'],
    ];

    private const GRAVITA_CONTROLLI = ['passed' => 0, 'needs_review' => 1, 'failed' => 2];

    private const STATI_FIRMA = [
        'Firmato' => ['heroicon-m-check-badge', 'success'],
        'In attesa di firma' => ['heroicon-m-clock', 'info'],
        'Rifiutata' => ['heroicon-m-x-circle', 'warning'],
        'Scaduta' => ['heroicon-m-x-circle', 'warning'],
        'Non riuscita' => ['heroicon-m-x-circle', 'warning'],
        'Annullata' => ['heroicon-m-minus-circle', 'gray'],
        'Da firmare' => ['heroicon-m-pencil-square', 'gray'],
    ];

    private const STADI_SCADENZA = [
        'scaduto' => ['heroicon-m-exclamation-triangle', 'danger'],
        'in_scadenza' => ['heroicon-m-clock', 'warning'],
        'valido' => ['heroicon-m-check-circle', 'success'],
    ];

    /** @var array<string, mixed>|null */
    private ?array $fascicoloCache = null;

    /** @var array<int|string, array{esito: ?string, dettaglio: ?string}> */
    private array $controlliCache = [];

    /**
     * @return Collection<int, Model>
     */
    private function applicablePlichi(): Collection
    {
        $taskClass = $this->plicoTaskClass();

        if ($taskClass === null) {
            return collect();
        }

        $owner = $this->getOwnerRecord();

        if ($owner instanceof Pratica) {
            $prodotto = $owner->tipoprodotto?->name;
            $stato = $owner->praticaStato?->codice;
            $task = $prodotto === null || $stato === null ? null : app(PracticeTaskDocuments::class)->taskDelloStato($prodotto, $stato);

            if ($task !== null) {
                return collect([$task->load('documentTypes')]);
            }

            if ($prodotto !== null) {
                return collect();
            }
        }

        return $taskClass::getAvailableFor($owner)->load('documentTypes')->values();
    }

    private function plicoTaskClass(): ?string
    {
        foreach (array_filter([
            config('unico-core.documents.task_model'),
            class_exists(\App\Models\Task::class) ? \App\Models\Task::class : null,
            \Unico\Core\Models\Task::class,
        ]) as $class) {
            if (is_string($class) && class_exists($class) && method_exists($class, 'getAvailableFor')) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Sulla pratica si vedono anche i documenti dei suoi client nati dai requisiti dei plichi della pratica (es. identità del coobbligato):
     * il query della relazione, che arriva solo ai documenti della pratica, viene sostituito.
     *
     * @return Builder<Document>|null
     */
    private function praticaQuery(Builder $query): ?Builder
    {
        $pratica = $this->getOwnerRecord();

        if (! $pratica instanceof Pratica) {
            return null;
        }

        $clients = $pratica->praticaClients()->pluck('client_id')->map(fn (mixed $id): string => (string) $id)->all();
        $clientRequirements = DocumentRequirement::query()->toBase()
            ->select('id')
            ->where('documentable', 'client')
            ->whereIn('task_id', \Unico\Core\Models\Task::query()->toBase()->select('id')->where('taskable', 'pratica'));

        return $query->getModel()->newQuery()->where(fn (Builder $query) => $query
            ->where(fn (Builder $own) => $own->where('documentable_type', 'pratica')->where('documentable_id', (string) $pratica->getKey()))
            ->orWhere(fn (Builder $theirs) => $theirs->where('documentable_type', 'client')->whereIn('documentable_id', $clients)->whereIn('document_requirement_id', $clientRequirements)));
    }

    /** Di chi è il documento: la pratica oppure il client (con il ruolo che ha nella pratica). */
    private function ownerLabel(Document $document): string
    {
        $pratica = $this->getOwnerRecord();

        if ($document->documentable_type !== 'client' || ! $pratica instanceof Pratica) {
            return 'Pratica';
        }

        $link = $pratica->praticaClients()->with('client')->where('client_id', $document->documentable_id)->first();
        $name = trim(($link?->client?->first_name ?? '').' '.($link?->client?->name ?? ''));

        return ($name !== '' ? $name : 'Cliente').($link?->role ? ' · '.$link->role : '');
    }

    /** L'azienda (tenant) ha anche i documenti aziendali interni: organigramma, verbali CdA. */
    private function ownerIsCompany(): bool
    {
        $owner = $this->getOwnerRecord();

        return (method_exists($owner, 'getMorphClass') ? $owner->getMorphClass() : strtolower(class_basename($owner))) === 'company';
    }

    /**
     * Tipi documento dei plichi che arrivano a questo record: quelli dei task del suo modello (`tasks.taskable`) e quelli che i task
     * di altri modelli fanno nascere su di lui (`document_requirements.documentable`, es. i documenti del cliente della pratica).
     */
    private function taskDocumentTypeIds(): \Illuminate\Database\Query\Builder
    {
        $owner = $this->getOwnerRecord();
        $alias = $owner instanceof Model ? \Unico\Core\MorphMap::aliasOf($owner) : strtolower(class_basename($owner));

        return DocumentRequirement::query()->toBase()
            ->select('document_type_id')
            ->where(fn ($query) => $query
                ->whereIn('task_id', \Unico\Core\Models\Task::query()->toBase()->select('id')->where('taskable', $alias))
                ->orWhere('documentable', $alias));
    }

    /**
     * @return array<int|string, string>
     */
    private function documentTypeOptions(bool $includeUsed = false, mixed $currentId = null, bool $excludeUsed = false): array
    {
        $taskTypes = $this->taskDocumentTypeIds();
        $limited = $taskTypes->exists() || $this->ownerIsCompany();

        return DocumentType::query()
            // Si propongono i tipi dei plichi che arrivano al record (e, per l'azienda, i documenti interni); senza plichi, tutti.
            ->when($limited, fn (Builder $query) => $query->where(function (Builder $query) use ($taskTypes, $includeUsed, $currentId): void {
                $query->whereIn('id', $taskTypes)
                    ->when($this->ownerIsCompany(), fn (Builder $query) => $query->orWhere('is_company', true))
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
                        ->label('Numero / protocollo documento')
                        ->placeholder('es. CI-2024-001'),
                    TextInput::make('emitted_by')
                        ->label('Rilasciato da')
                        ->default(fn ($get) => $get('document_type_id') ? DocumentType::find($get('document_type_id'))?->emitted_by : null)
                        ->placeholder('es. Comune di Napoli')
                        ->maxLength(255),
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
                ->mountUsing(function (Action $action, Schema $schema): void {
                    if ($this->applicablePlichi()->isEmpty() && ! $this->getOwnerRecord() instanceof Pratica) {
                        Notification::make()->title('Nessun plico applicabile')->body('Per lo stato attuale di questo record non ci sono plichi documentali.')->warning()->send();
                        $action->cancel();

                        return;
                    }

                    $schema->fill(['plichi' => $this->applicablePlichi()->pluck('id')->all()]);
                })
                ->schema(fn (): array => [
                    CheckboxList::make('plichi')
                        ->label('Plichi')
                        ->options(fn (): array => $this->applicablePlichi()->mapWithKeys(fn (Model $task): array => [$task->getKey() => $task->name])->all())
                        ->descriptions(fn (): array => $this->applicablePlichi()->mapWithKeys(fn (Model $task): array => [
                            $task->getKey() => $task->documentTypes->count().' documenti ('.$task->documentTypes->filter(fn ($type) => $type->pivot->is_required)->count().' obbligatori)',
                        ])->all())
                        ->default(fn (): array => $this->applicablePlichi()->pluck('id')->all())
                        ->required(fn (): bool => ! $this->getOwnerRecord() instanceof Pratica)
                        ->hidden(fn (): bool => $this->getOwnerRecord() instanceof Pratica && $this->applicablePlichi()->isEmpty())
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

                    if ($owner instanceof Pratica) {
                        $created += app(PracticeTaskDocuments::class)->attachChecklist($owner, $this->gruppoChecklist($owner), $owner->clienti_id);
                    }

                    Notification::make()
                        ->title($created.' documenti richiesti creati')
                        ->body($existing > 0 ? $existing.' erano già presenti.' : null)
                        ->success()
                        ->send();
                }),
            $this->plicoTaskClass() === null ? null : Action::make('scaricaPlico')
                ->label('Scarica plico')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->modalHeading('Scarica i documenti del plico')
                ->modalDescription('Un archivio ZIP con i file dei documenti dei plichi scelti, con un indice.')
                ->modalSubmitActionLabel('Scarica')
                ->mountUsing(function (Action $action, Schema $schema): void {
                    if ($this->applicablePlichi()->isEmpty()) {
                        Notification::make()->title('Nessun plico applicabile')->body('Per lo stato attuale di questo record non ci sono plichi documentali.')->warning()->send();
                        $action->cancel();

                        return;
                    }

                    $schema->fill(['plichi' => $this->applicablePlichi()->pluck('id')->all()]);
                })
                ->schema(fn (): array => [
                    CheckboxList::make('plichi')
                        ->label('Plichi')
                        ->options(fn (): array => $this->applicablePlichi()->mapWithKeys(fn (Model $task): array => [$task->getKey() => $task->name])->all())
                        ->default(fn (): array => $this->applicablePlichi()->pluck('id')->all())
                        ->required()
                        ->columns(1),
                ])
                ->action(function (array $data) {
                    try {
                        $percorso = app(PlicoArchive::class)->zip($this->applicablePlichi()->whereIn('id', $data['plichi'] ?? []), $this->getOwnerRecord());
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Nessun file da scaricare')->body($e->getMessage())->warning()->send();

                        return null;
                    }

                    return response()->download($percorso, 'plico-'.Str::slug((string) $this->getOwnerRecord()->getKey()).'.zip')->deleteFileAfterSend();
                }),
            Action::make('inviaPlicoOtp')
                ->label('Invia plico per la firma OTP')
                ->icon('heroicon-o-device-phone-mobile')
                ->color('primary')
                ->visible(fn (): bool => \checkPiano('firma', static::class, write: true) && $this->documentiDaFirmare()->isNotEmpty())
                ->modalHeading('Invia in firma i documenti')
                ->modalDescription('I documenti da firmare partono in un unico plico: il firmatario riceve una sola richiesta e firma tutto con un solo codice OTP.')
                ->modalSubmitActionLabel('Invia')
                ->schema(fn (): array => [
                    CheckboxList::make('documenti')
                        ->label('Documenti da firmare')
                        ->options(fn (): array => $this->documentiDaFirmare()->mapWithKeys(fn (Document $documento): array => [$documento->getKey() => (string) $documento->name])->all())
                        ->default(fn (): array => $this->documentiDaFirmare()->modelKeys())
                        ->required()
                        ->columns(1),
                ])
                ->mountUsing(function (Schema $schema): void {
                    $schema->fill(['documenti' => $this->documentiDaFirmare()->modelKeys()]);
                })
                ->action(function (array $data): void {
                    $scelti = $this->documentiDaFirmare()->whereIn('id', $data['documenti'] ?? []);

                    foreach ($scelti->groupBy(fn (Document $documento): string => $documento->documentable_type.':'.$documento->documentable_id) as $gruppo) {
                        $avviso = self::missingSignerContacts($gruppo->first());

                        if ($avviso !== null) {
                            Notification::make()->warning()->title('Firma OTP non possibile')->body($avviso)->send();

                            continue;
                        }

                        $firmatari = [];

                        foreach ($gruppo as $documento) {
                            $firmatari += SignerDefaults::for($documento);
                        }

                        try {
                            app(SignatureRequestService::class)->sendBundle($gruppo->values()->all(), array_values($firmatari), auth()->user());
                            Notification::make()->success()->title($gruppo->count() > 1 ? 'Plico inviato per la firma' : 'Richiesta di firma inviata')->body($gruppo->count().' documenti, un solo codice OTP.')->send();
                        } catch (SignatureRequestException $e) {
                            Notification::make()->danger()->title('Invio per la firma non riuscito')->body($e->getMessage())->send();
                        }
                    }
                }),
            CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $owner = $this->getOwnerRecord();
                    $data['company_id'] = is_numeric($owner->company_id ?? null)
                        ? (int) $owner->company_id
                        : ($owner instanceof Company ? $owner->getKey() : null);

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
            Filter::make('da_completare')
                ->label('Da completare (mancanti, respinti, anomali, integrativi)')
                ->query(fn ($query) => $query->whereIn('status', [DocumentStatus::PENDING->value, DocumentStatus::REJECTED->value, DocumentStatus::ANOMALY->value, DocumentStatus::SUPPLEMENTARY->value])),
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
            ->modifyQueryUsing(fn (Builder $query) => ($this->praticaQuery($query) ?? $query)->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])->with(['signatureRequests', 'media', 'documentType.pdfModule', 'analyses']))
            ->description(fn (): ?HtmlString => $this->descrizioneFascicolo())
            ->defaultSort('expires_at', 'desc')
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('intestatario')
                    ->label('Intestatario')
                    ->state(fn (Document $record): string => $this->ownerLabel($record))
                    ->badge()
                    ->color(fn (Document $record): string => $record->documentable_type === 'client' ? 'info' : 'gray')
                    ->visible(fn (): bool => $this->getOwnerRecord() instanceof Pratica),
                TextColumn::make('name')
                    ->label('Documento')
                    ->searchable()
                    ->sortable()
                    ->default('Senza documento')
                    ->url(fn (Document $record): ?string => Route::has('documents.download') ? $record->download_url : null, shouldOpenInNewTab: true)
                    ->color(fn (Document $record): ?string => (Route::has('documents.download') && $record->download_url) ? 'info' : null),
                IconColumn::make('fascicolo')
                    ->label('Fascicolo')
                    ->state(fn (Document $record): ?string => $this->requisito($record)['fase'] ?? null)
                    ->icon(fn (?string $state): ?string => self::FASI_FASCICOLO[$state][0] ?? null)
                    ->color(fn (?string $state): string => self::FASI_FASCICOLO[$state][1] ?? 'gray')
                    ->tooltip(fn (Document $record): ?string => $this->requisito($record)['descrizione'] ?? null)
                    ->visible(fn (): bool => $this->fascicolo()['task'] !== []),
                IconColumn::make('status')
                    ->label('Stato')
                    ->icon(fn (Document $record): ?string => $record->status?->getIcon())
                    ->color(fn (Document $record): string|array|null => $record->status?->getColor())
                    ->tooltip(fn (Document $record): ?string => $record->status?->getLabel().($record->rejection_note ? ': '.$record->rejection_note : ''))
                    ->sortable(),
                IconColumn::make('controlli')
                    ->label('Controlli')
                    ->state(fn (Document $record): ?string => $this->esitoControlli($record)['esito'])
                    ->icon(fn (?string $state): ?string => self::ESITI_CONTROLLI[$state][0] ?? null)
                    ->color(fn (?string $state): string => self::ESITI_CONTROLLI[$state][1] ?? 'gray')
                    ->tooltip(fn (Document $record): ?string => $this->esitoControlli($record)['dettaglio']),
                IconColumn::make('signature_state')
                    ->label('Firma')
                    ->state(fn (Document $record): ?string => self::signatureState($record, true))
                    ->icon(fn (?string $state): ?string => self::STATI_FIRMA[$state][0] ?? null)
                    ->color(fn (?string $state): string => self::STATI_FIRMA[$state][1] ?? 'gray')
                    ->tooltip(fn (?string $state): ?string => $state),
                IconColumn::make('otp')
                    ->label('OTP')
                    ->state(fn (Document $record): ?string => self::otpState($record))
                    ->icon(fn (?string $state): ?string => match ($state) {
                        'Invia OTP' => 'heroicon-o-device-phone-mobile',
                        'OTP inviato' => 'heroicon-o-paper-airplane',
                        default => null,
                    })
                    ->color(fn (?string $state): string => $state === 'Invia OTP' ? 'primary' : 'info')
                    ->tooltip(fn (?string $state): ?string => $state === 'Invia OTP' ? 'Invia il codice OTP al cliente per la firma' : $state)
                    ->action(SendForSignatureAction::make(
                        onlyForSignedTypes: true,
                        preflight: fn (Document $document): ?string => self::missingSignerContacts($document),
                    )),
                IconColumn::make('scadenza')
                    ->label('Scadenza')
                    ->state(fn (Document $record): ?string => self::stadioScadenza($record))
                    ->icon(fn (?string $state): ?string => self::STADI_SCADENZA[$state][0] ?? null)
                    ->color(fn (?string $state): string => self::STADI_SCADENZA[$state][1] ?? 'gray')
                    ->tooltip(fn (Document $record): ?string => $record->expires_at === null ? null : 'Scade il '.$record->expires_at->format('d/m/Y').($record->expires_at->isPast() ? ' (scaduto)' : ' (tra '.now()->startOfDay()->diffInDays($record->expires_at->startOfDay()).' giorni)'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('expires_at', $direction)),
                TextColumn::make('emitted_at')
                    ->label('Emissione')
                    ->date('d/m/y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expires_at')
                    ->label('Data scadenza')
                    ->date('d/m/y')
                    ->sortable()
                    ->color(fn ($record) => $record?->expires_at?->isPast() ? 'danger' : 'gray')
                    ->weight(fn ($record) => $record?->expires_at?->isPast() ? 'bold' : 'normal')
                    ->toggleable(isToggledHiddenByDefault: true),
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
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters($filters)
            ->headerActions($headerActions)
            ->recordActions([
                DocumentOutcomeAction::make(),
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
                Action::make('approvaConFirma')
                    ->label('Approva con firma')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Document $record): bool => $record->awaitsApproval() && self::approverFor($record) !== null)
                    ->requiresConfirmation()
                    ->modalHeading('Approva il documento firmato')
                    ->modalDescription('Confermi l\'approvazione con la tua firma? Resta registrata con data e ora.')
                    ->action(function (Document $record): void {
                        $record->approve(self::approverFor($record));

                        Notification::make()->title('Documento approvato')->success()->send();
                    }),
                Action::make('richiediApi')
                    ->label('Richiedi via API')
                    ->icon('heroicon-o-cloud-arrow-down')
                    ->color('info')
                    ->visible(fn (Document $record): bool => app(DocumentApiService::class)->supports($record->documentType))
                    ->requiresConfirmation()
                    ->modalHeading('Richiedi il documento via API')
                    ->modalDescription(fn (Document $record): string => 'Il documento viene richiesto al servizio «'.(app(DocumentApiService::class)->services()[$record->documentType?->api_service] ?? '').'». Alcuni servizi sono a pagamento.')
                    ->action(function (Document $record): void {
                        $result = app(DocumentApiService::class)->request($record);
                        $done = $result->ok && ! $result->simulated;
                        $notification = Notification::make()->title($result->ok ? 'Richiesta API' : 'Richiesta API non riuscita')->body($result->message);

                        $done ? $notification->success() : $notification->warning();
                        $notification->send();
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
                    DocumentOutcomeAction::bulk(),
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

    /** Chi può approvare il documento col proprio utente: l'agente, il dipendente o l'utente indicati da `approver_model`. */
    private static function approverFor(Document $document): ?Model
    {
        $user = auth()->user();
        $alias = $document->documentType?->approver_model;

        if ($user === null || blank($alias)) {
            return null;
        }

        return match ($alias) {
            'fornitore' => \Unico\Core\Models\Fornitore::query()->where('user_id', $user->getAuthIdentifier())->first(),
            'employee' => \Unico\Core\Models\Employee::query()->where('user_id', $user->getAuthIdentifier())->first(),
            'user' => $user instanceof Model ? $user : null,
            default => null,
        };
    }

    /** Il tipo documento è richiesto da un plico del modello (taskable) o nasce su di lui da un plico di un altro modello. */
    private static function requiredOn(string $alias, DocumentType $type): bool
    {
        return DocumentRequirement::query()
            ->where('document_type_id', $type->getKey())
            ->where(fn ($query) => $query
                ->whereIn('task_id', \Unico\Core\Models\Task::query()->toBase()->select('id')->where('taskable', $alias))
                ->orWhere('documentable', $alias))
            ->exists();
    }

    private static function canPrintModule(Document $document): bool
    {
        $owner = $document->documentable;
        $type = $document->documentType;

        return match (true) {
            $owner instanceof Client => $type !== null && $type->appliesToPerson((bool) $owner->is_person) && self::requiredOn('client', $type),
            $owner instanceof Pratica => (
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

    /**
     * A che punto sono i documenti dei task (plichi) in essere per il record: per ogni requisito il punto in cui è, per ogni task i totali.
     *
     * @return array{task: list<array<string, mixed>>, perTipo: array<int|string, array<string, mixed>>}
     */
    private function fascicolo(): array
    {
        if ($this->fascicoloCache !== null) {
            return $this->fascicoloCache;
        }

        $owner = $this->getOwnerRecord();
        $documenti = $owner->documents()->get(['id', 'document_type_id', 'status', 'is_signed'])->groupBy('document_type_id');
        $task = [];
        $perTipo = [];

        foreach ($this->taskInEssere() as $plico) {
            $riga = ['nome' => $plico['nome'], 'obbligatori' => 0, 'presenti' => 0, 'da_firmare' => 0, 'problemi' => 0, 'mancanti' => []];

            foreach ($plico['requisiti']->filter(fn (array $requisito): bool => $requisito['tipo'] !== null) as $requisito) {
                $tipo = $requisito['tipo'];
                $fase = $this->faseRequisito($tipo, $documenti->get($tipo->getKey(), collect()), $requisito['obbligatorio']);
                $perTipo[$tipo->getKey()] ??= ['fase' => $fase, 'descrizione' => ($requisito['obbligatorio'] ? 'Obbligatorio' : 'Facoltativo')." nel fascicolo «{$plico['nome']}»: ".self::NOMI_FASI[$fase]];

                if (! $requisito['obbligatorio']) {
                    continue;
                }

                $riga['obbligatori']++;
                $riga['presenti'] += in_array($fase, ['ok', 'caricato', 'da_firmare'], true) ? 1 : 0;
                $riga['da_firmare'] += $fase === 'da_firmare' ? 1 : 0;
                $riga['problemi'] += $fase === 'problema' ? 1 : 0;

                if (in_array($fase, ['mancante', 'problema'], true)) {
                    $riga['mancanti'][] = (string) $tipo->name;
                }
            }

            $task[] = $riga;
        }

        return $this->fascicoloCache = ['task' => $task, 'perTipo' => $perTipo];
    }

    /**
     * I task in essere del record, con i loro requisiti. Per una pratica sono quelli del prodotto: la check list del gruppo di tipologia
     * lavorativa del richiedente (documenti da chiedere al cliente) e il task dello stato attuale (es. la modulistica del fascicolo
     * completo), filtrati per banca; per gli altri record i plichi applicabili.
     *
     * @return list<array{nome: string, requisiti: Collection<int, array{tipo: ?DocumentType, obbligatorio: bool}>}>
     */
    private function taskInEssere(): array
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof Pratica) {
            return $this->applicablePlichi()->map(fn (Model $plico): array => [
                'nome' => (string) $plico->name,
                'requisiti' => method_exists($plico, 'requisitiEffettivi')
                    ? $plico->requisitiEffettivi()->map(fn ($requisito): array => ['tipo' => $requisito->documentType, 'obbligatorio' => (bool) $requisito->is_required])
                    : $plico->documentTypes->map(fn ($tipo): array => ['tipo' => $tipo, 'obbligatorio' => (bool) $tipo->pivot->is_required]),
            ])->all();
        }

        $prodotto = $owner->tipoprodotto?->name;

        if ($prodotto === null) {
            return [];
        }

        $documenti = app(PracticeTaskDocuments::class);
        $gruppo = $this->gruppoChecklist($owner);
        $converti = fn (Collection $requisiti): Collection => $requisiti->map(fn (DocumentRequirement $requisito): array => ['tipo' => $requisito->documentType, 'obbligatorio' => (bool) $requisito->is_required]);

        $task = [['nome' => 'Check list'.($gruppo ? ' · '.$gruppo : ''), 'requisiti' => $converti($documenti->forProduct($prodotto, $gruppo, $owner->clienti_id))]];
        $stato = $owner->praticaStato?->codice;

        if ($stato !== null) {
            $task[] = ['nome' => 'Fase '.$stato, 'requisiti' => $converti($documenti->forProductStatus($prodotto, $stato, $owner->clienti_id))];
        }

        return array_values(array_filter($task, fn (array $blocco): bool => $blocco['requisiti']->isNotEmpty()));
    }

    /** Il gruppo di documenti della tipologia lavorativa del richiedente, che sceglie la check list della pratica. */
    private function gruppoChecklist(Pratica $pratica): ?string
    {
        $cliente = app(PracticeModuleData::class)->findClient($pratica);
        $gruppo = blank($cliente?->employment_typology) ? null : TipoProdottoSub::query()->where('name', $cliente->employment_typology)->value('employment_document_group');

        return $gruppo instanceof \BackedEnum ? $gruppo->value : ($gruppo === null ? null : (string) $gruppo);
    }

    /**
     * I documenti del record che si possono mandare in firma: tipo da firmare, PDF presente, firmatari previsti dal modulo e nessuna
     * richiesta già aperta.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Document>
     */
    private function documentiDaFirmare(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->getTable()->getQuery()
            ->with('documentType.pdfModule')
            ->get()
            ->filter(fn (Document $documento): bool => (bool) $documento->documentType?->is_signed
                && $documento->getFirstMedia(Document::COLLECTION)?->mime_type === 'application/pdf'
                && SendForSignatureAction::isSignable($documento))
            ->values();
    }

    /** @param  Collection<int, Document>  $documenti */
    private function faseRequisito(DocumentType $tipo, Collection $documenti, bool $obbligatorio): string
    {
        $validi = $documenti->filter(fn (Document $documento): bool => $documento->status instanceof DocumentStatus && $documento->status->soddisfa());

        if ($validi->isEmpty()) {
            $conProblema = $documenti->contains(fn (Document $documento): bool => in_array($documento->status, [DocumentStatus::ANOMALY, DocumentStatus::REJECTED, DocumentStatus::SUPPLEMENTARY, DocumentStatus::EXPIRED, DocumentStatus::UNREADABLE], true));

            return $conProblema ? 'problema' : ($obbligatorio ? 'mancante' : 'facoltativo');
        }

        if ($tipo->is_signed && ! $validi->contains(fn (Document $documento): bool => (bool) $documento->is_signed)) {
            return 'da_firmare';
        }

        return $validi->contains(fn (Document $documento): bool => in_array($documento->status, [DocumentStatus::APPROVED, DocumentStatus::NA], true)) ? 'ok' : 'caricato';
    }

    /** @return array{fase: string, descrizione: string}|null */
    private function requisito(Document $documento): ?array
    {
        return $this->fascicolo()['perTipo'][$documento->document_type_id] ?? null;
    }

    private function descrizioneFascicolo(): ?HtmlString
    {
        $task = $this->fascicolo()['task'];

        if ($task === []) {
            return null;
        }

        $blocchi = array_map(function (array $riga): string {
            $completo = $riga['obbligatori'] > 0 && $riga['presenti'] === $riga['obbligatori'] && $riga['problemi'] === 0;
            $percentuale = $riga['obbligatori'] === 0 ? 100 : (int) round($riga['presenti'] / $riga['obbligatori'] * 100);
            $colore = $completo ? '#16a34a' : ($riga['problemi'] > 0 ? '#dc2626' : '#d97706');
            $dettagli = array_filter([
                $riga['da_firmare'] > 0 ? $riga['da_firmare'].' da firmare' : null,
                $riga['problemi'] > 0 ? $riga['problemi'].' da correggere' : null,
                $riga['mancanti'] !== [] ? 'mancano: '.e(implode(', ', array_slice($riga['mancanti'], 0, 6))).(count($riga['mancanti']) > 6 ? ' e altri '.(count($riga['mancanti']) - 6) : '') : null,
            ]);

            return '<div style="margin-top:.5rem"><strong>'.e($riga['nome']).'</strong> — '.$riga['presenti'].'/'.$riga['obbligatori'].' documenti obbligatori presenti'
                .'<div style="height:6px;margin:.25rem 0;border-radius:3px;background:rgba(127,127,127,.25)"><div style="height:6px;width:'.$percentuale.'%;border-radius:3px;background:'.$colore.'"></div></div>'
                .($dettagli === [] ? '' : '<span style="opacity:.75">'.implode(' · ', $dettagli).'</span>')
                .'</div>';
        }, $task);

        return new HtmlString('<div class="text-sm">'.implode('', $blocchi).'</div>');
    }

    /**
     * Esito dei controlli di un documento: il peggiore tra l'ultima verifica tecnica, la classificazione e la lettura AI, con le anomalie.
     *
     * @return array{esito: ?string, dettaglio: ?string}
     */
    private function esitoControlli(Document $documento): array
    {
        return $this->controlliCache[$documento->getKey()] ??= (function () use ($documento): array {
            $ultime = $documento->analyses->sortByDesc('id')->unique('kind');

            if ($ultime->isEmpty()) {
                return ['esito' => null, 'dettaglio' => null];
            }

            $esito = $ultime->pluck('outcome')->map(fn ($valore): string => (string) $valore)->sortByDesc(fn (string $valore): int => self::GRAVITA_CONTROLLI[$valore] ?? 0)->first();
            $segnalazioni = $ultime->flatMap(fn ($analisi): array => array_merge(
                array_map(fn (string $anomalia): string => DocumentAnomaly::tryFrom($anomalia)?->label() ?? $anomalia, (array) ($analisi->result['anomalies'] ?? [])),
                array_map('strval', (array) ($analisi->result['issues'] ?? [])),
            ))->unique()->values();

            return ['esito' => $esito, 'dettaglio' => $segnalazioni->isEmpty() ? ($esito === 'passed' ? 'Controlli superati' : null) : $segnalazioni->implode(' · ')];
        })();
    }

    private static function stadioScadenza(Document $documento): ?string
    {
        if ($documento->expires_at === null) {
            return null;
        }

        return match (true) {
            $documento->expires_at->isPast() => 'scaduto',
            now()->diffInDays($documento->expires_at) <= 30 => 'in_scadenza',
            default => 'valido',
        };
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

    private static function signatureState(Document $document, bool $soloDaFirmare = false): ?string
    {
        if ($document->is_signed) {
            return 'Firmato';
        }

        // Nelle colonne con le icone un documento che non va firmato non mostra nulla.
        if ($soloDaFirmare && ! $document->documentType?->is_signed && $document->latestSignatureRequest() === null) {
            return null;
        }

        return match ($document->latestSignatureRequest()?->status) {
            SignatureRequestStatus::Pending, SignatureRequestStatus::Sent => 'In attesa di firma',
            SignatureRequestStatus::Declined => 'Rifiutata',
            SignatureRequestStatus::Expired => 'Scaduta',
            SignatureRequestStatus::Cancelled => 'Annullata',
            SignatureRequestStatus::Failed => 'Non riuscita',
            default => 'Da firmare',
        };
    }
}
