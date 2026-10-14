<?php

namespace Unico\Core\Filament\Schemas;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Unico\Core\Documents\PracticeSignaturePackage;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\Pratica;

/**
 * Modulistica da firmare di una pratica, solo componenti di form Filament: elenco dei moduli con quelli firmati spuntati, i dati
 * che mancano per stamparli e le azioni «Stampa e invia per la firma OTP» e «Aggiorna stato».
 *
 *     PracticeSignaturePackageForm::components(fn () => $pratica, fn () => $tipiModulistica)
 */
class PracticeSignaturePackageForm
{
    private const STATES = [
        'senza_modulo' => 'modulo PDF non disponibile (da stampare a parte)',
        'dati_mancanti' => 'dati mancanti',
        'pronto' => 'pronto da stampare',
        'stampato' => 'stampato',
        'inviato' => 'inviato: in attesa della firma OTP',
        'firmato' => 'firmato',
        'rifiutato' => 'firma non completata',
    ];

    /**
     * @param  Closure(): ?Pratica  $pratica
     * @param  Closure(): Collection<int, DocumentType>  $types  tipi documento della modulistica
     * @param  Closure(list<array{name: string, ok: bool, message: string}>): void|null  $afterSend
     * @return list<Section>
     */
    public static function components(Closure $pratica, Closure $types, ?Closure $afterSend = null, string $heading = 'Modulistica da firmare'): array
    {
        $status = fn (): array => ($record = $pratica()) === null ? [] : app(PracticeSignaturePackage::class)->status($record, $types());
        $signed = fn (): array => array_keys(array_filter($status(), fn (array $entry): bool => $entry['state'] === 'firmato'));

        return [
            Section::make($heading)
                ->visible(fn (): bool => (bool) config('unico-core.signature.practice_package', true))
                ->schema([
                    CheckboxList::make('firmati')
                        ->label('Moduli')
                        ->options(fn (): array => $types()->mapWithKeys(fn (DocumentType $type): array => [$type->getKey() => $type->name])->all())
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(fn (CheckboxList $component) => $component->state($signed())),
                    Text::make(fn (): HtmlString => static::summary($status()))->color('gray'),
                    Actions::make([
                        Action::make('inviaPerFirma')
                            ->label('Stampa e invia per la firma OTP')
                            ->icon('heroicon-o-pencil-square')
                            ->requiresConfirmation()
                            ->modalHeading('Stampa e invia per la firma')
                            ->modalDescription('Stampo i moduli compilati con i dati della pratica e li invio al cliente (e al collaboratore dove serve) per la firma con codice OTP via SMS.')
                            ->modalSubmitActionLabel('Invia')
                            ->action(function (Set $set) use ($pratica, $types, $signed, $afterSend): void {
                                $record = $pratica();

                                if ($record === null) {
                                    return;
                                }

                                $results = app(PracticeSignaturePackage::class)->send($record, $types(), auth()->user());

                                foreach ($results as $result) {
                                    Notification::make()->title($result['name'])->body($result['message'])->{$result['ok'] ? 'success' : 'warning'}()->send();
                                }

                                $set('firmati', $signed());

                                if ($afterSend !== null) {
                                    $afterSend($results);
                                }
                            }),
                        Action::make('aggiornaFirme')
                            ->label('Aggiorna stato')
                            ->icon('heroicon-o-arrow-path')
                            ->color('gray')
                            ->action(function (Set $set) use ($pratica, $types, $signed): void {
                                if (($record = $pratica()) !== null) {
                                    app(PracticeSignaturePackage::class)->refresh($record, $types());
                                    $set('firmati', $signed());
                                }
                            }),
                    ]),
                ]),
        ];
    }

    /** @param array<int, array{state: string, name: string, missing: list<string>}> $status */
    private static function summary(array $status): HtmlString
    {
        $rows = array_map(function (array $entry): string {
            $detail = $entry['state'] === 'dati_mancanti' ? ': '.implode(', ', $entry['missing']) : '';

            return '<li><strong>'.e($entry['name']).'</strong> — '.e(self::STATES[$entry['state']] ?? $entry['state']).e($detail).'</li>';
        }, $status);

        return new HtmlString('<ul class="list-disc pl-5 text-sm">'.implode('', $rows).'</ul>');
    }
}
