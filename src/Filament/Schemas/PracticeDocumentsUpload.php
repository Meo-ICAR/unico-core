<?php

namespace Unico\Core\Filament\Schemas;

use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Unico\Core\Documents\IntakeResult;
use Unico\Core\Documents\PracticeDocumentIntake;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\Pratica;

/**
 * Elenco dei documenti da caricare per una pratica con un solo campo di caricamento: ogni file viene
 * riconosciuto (regex sul nome, poi AI), letto e archiviato, e l'elenco si ripresenta con i documenti ricevuti spuntati.
 * Solo componenti di form Filament: si inserisce nello schema di qualsiasi pagina o resource.
 *
 *     PracticeDocumentsUpload::components(fn () => $pratica, fn () => $tipiAttesi)
 */
class PracticeDocumentsUpload
{
    /**
     * @param  Closure(): ?Pratica  $pratica
     * @param  Closure(): Collection<int, DocumentType>  $expected  tipi documento attesi
     * @param  Closure(list<IntakeResult>): void|null  $afterUpload  eseguita dopo ogni caricamento
     * @return list<Section>
     */
    public static function components(Closure $pratica, Closure $expected, ?Closure $afterUpload = null, string $heading = 'Documenti da caricare'): array
    {
        $received = fn (): array => static::receivedIds($pratica(), $expected());

        return [
            Section::make($heading)
                ->visible(fn (): bool => (bool) config('unico-core.practice_intake.enabled', true))
                ->schema([
                    CheckboxList::make('ricevuti')
                        ->label('Documenti richiesti')
                        ->options(fn (): array => $expected()->mapWithKeys(fn (DocumentType $type): array => [$type->getKey() => $type->name])->all())
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(fn (CheckboxList $component) => $component->state($received())),
                    FileUpload::make('files')
                        ->label('Carica i documenti')
                        ->helperText('Anche più file insieme: riconosco io di che documento si tratta.')
                        ->multiple()
                        ->storeFiles(false)
                        ->dehydrated(false)
                        ->live()
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                        ->maxSize(10240)
                        ->afterStateUpdated(function (mixed $state, Set $set) use ($pratica, $expected, $received, $afterUpload): void {
                            $record = $pratica();
                            $files = array_filter((array) $state, fn (mixed $file): bool => $file instanceof UploadedFile);

                            if ($record === null || $files === []) {
                                return;
                            }

                            $results = static::process($record, $files, $expected());

                            $set('files', []);
                            $set('ricevuti', $received());

                            if ($afterUpload !== null) {
                                $afterUpload($results);
                            }
                        }),
                ]),
        ];
    }

    /**
     * Carica i file e segnala l'esito di ciascuno.
     *
     * @param  array<int, UploadedFile>  $files
     * @param  Collection<int, DocumentType>  $expected
     * @return list<IntakeResult>
     */
    public static function process(Pratica $pratica, array $files, Collection $expected): array
    {
        $intake = app(PracticeDocumentIntake::class);
        $results = [];

        foreach ($files as $file) {
            $result = $intake->intake($pratica, $file, $expected, auth()->user());
            $results[] = $result;

            static::notify($result);
        }

        return $results;
    }

    /** @return list<int> */
    public static function receivedIds(?Pratica $pratica, Collection $expected): array
    {
        if ($pratica === null) {
            return [];
        }

        return array_keys(array_filter(app(PracticeDocumentIntake::class)->received($pratica, $expected)));
    }

    private static function notify(IntakeResult $result): void
    {
        $notification = Notification::make();

        if (! $result->matched()) {
            $notification->title($result->fileName)->body('Non ho riconosciuto il documento fra quelli richiesti: resta da verificare.')->warning();
        } elseif ($result->passed()) {
            $notification->title($result->fileName)->body('Riconosciuto come '.$result->type->name.'.')->success();
        } else {
            $notification->title($result->fileName)
                ->body('Riconosciuto come '.$result->type->name.($result->issues === [] ? ': da verificare in back office.' : ': '.implode(' ', $result->issues)))
                ->warning();
        }

        $notification->send();
    }
}
