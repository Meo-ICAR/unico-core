<?php

namespace Unico\Core\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;
use Unico\Core\Enums\SignerRole;
use Unico\Core\Models\Document;
use Unico\Core\Signature\Exceptions\SignatureRequestException;
use Unico\Core\Signature\SignatureProviderManager;
use Unico\Core\Signature\SignatureRequestService;
use Unico\Core\Signature\SignerDefaults;
use Unico\Core\Signature\SignerInput;

/**
 * Invio per la firma di un Document; sul record puo' essere il Document stesso o un modello con relazione `document`.
 */
class SendForSignatureAction
{
    /**
     * @param  (Closure(Document): ?string)|null  $preflight  ritorna un avviso se l'invio non e' possibile
     */
    public static function make(?string $name = 'sendForSignature', bool $onlyForSignedTypes = false, ?Closure $preflight = null): Action
    {
        return Action::make($name)
            ->label('Invia per firma')
            ->icon('heroicon-o-pencil-square')
            ->color('primary')
            ->modalHeading('Invia per firma')
            ->modalSubmitActionLabel('Invia')
            ->beforeFormFilled(function (Action $action, $record) use ($preflight): void {
                $document = self::resolveDocument($record);
                $warning = ($preflight !== null && $document !== null) ? $preflight($document) : null;

                if ($warning !== null) {
                    Notification::make()->warning()->title('Firma OTP non possibile')->body($warning)->send();
                    $action->cancel();
                }
            })
            ->visible(function (Action $action, $record) use ($onlyForSignedTypes): bool {
                $document = self::resolveDocument($record);

                if ($document === null || ! \checkPiano('firma', $action->getLivewire()::class, write: true)) {
                    return false;
                }

                if ($onlyForSignedTypes && ! $document->documentType?->is_signed) {
                    return false;
                }

                return self::isSignable($document);
            })
            ->fillForm(function ($record): array {
                $document = self::resolveDocument($record);
                $defaults = $document === null ? [] : SignerDefaults::for($document);
                $state = [];

                foreach (self::slotsOf($document) as $slot) {
                    $default = $defaults[$slot['slot']] ?? null;
                    $state[$slot['slot']] = [
                        'first_name' => $default?->firstName,
                        'last_name' => $default?->lastName,
                        'email' => $default?->email,
                        'phone' => $default?->phone,
                        'tax_code' => $default?->taxCode,
                    ];
                }

                return $state;
            })
            ->schema(function ($record): array {
                $required = app(SignatureProviderManager::class)->provider()->requiredContactFields();

                return collect(self::slotsOf(self::resolveDocument($record)))
                    ->map(fn (array $slot): Section => Section::make('Firmatario: '.(SignerRole::tryFrom((string) ($slot['role'] ?? ''))?->getLabel() ?? $slot['slot']))
                        ->statePath($slot['slot'])
                        ->columns(2)
                        ->schema([
                            TextInput::make('first_name')->label('Nome')->required()->maxLength(100),
                            TextInput::make('last_name')->label('Cognome')->required()->maxLength(100),
                            TextInput::make('email')->label('Email')->email()->required(in_array('email', $required, true)),
                            TextInput::make('phone')->label('Telefono')->tel()->required(in_array('phone', $required, true)),
                            TextInput::make('tax_code')->label('Codice fiscale')->maxLength(32),
                        ]))
                    ->all();
            })
            ->action(function (array $data, $record): void {
                $document = self::resolveDocument($record);

                if ($document === null) {
                    return;
                }

                $defaults = SignerDefaults::for($document);
                $signers = [];

                foreach (self::slotsOf($document) as $slot) {
                    $signers[] = self::buildSigner($slot, (array) ($data[$slot['slot']] ?? []), $defaults[$slot['slot']] ?? null);
                }

                try {
                    app(SignatureRequestService::class)->send($document, $signers, auth()->user());
                } catch (SignatureRequestException $e) {
                    Notification::make()->danger()->title('Invio per la firma non riuscito')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Richiesta di firma inviata')->send();
            });
    }

    public static function resolveDocument(mixed $record): ?Document
    {
        if ($record instanceof Document) {
            return $record;
        }

        if ($record instanceof Model && method_exists($record, 'document')) {
            return $record->document;
        }

        return null;
    }

    public static function isSignable(Document $document): bool
    {
        if ($document->is_signed || self::slotsOf($document) === []) {
            return false;
        }

        $latest = $document->latestSignatureRequest();

        return ! ($latest?->isOpen() ?? false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function slotsOf(?Document $document): array
    {
        return array_values($document?->documentType?->pdfModule?->signature_slots ?? []);
    }

    /**
     * @param  array<string, mixed>  $slot
     * @param  array<string, mixed>  $data
     */
    private static function buildSigner(array $slot, array $data, ?SignerInput $default): SignerInput
    {
        $values = [
            'firstName' => trim((string) ($data['first_name'] ?? '')),
            'lastName' => trim((string) ($data['last_name'] ?? '')),
            'email' => filled($data['email'] ?? null) ? trim((string) $data['email']) : null,
            'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
            'taxCode' => filled($data['tax_code'] ?? null) ? trim((string) $data['tax_code']) : null,
        ];

        $edited = $default === null
            || $values['firstName'] !== trim($default->firstName)
            || $values['lastName'] !== trim($default->lastName)
            || $values['email'] !== $default->email
            || $values['phone'] !== $default->phone
            || $values['taxCode'] !== $default->taxCode;

        return new SignerInput(
            $slot['slot'],
            SignerRole::from((string) $slot['role']),
            $values['firstName'],
            $values['lastName'],
            $values['email'],
            $values['phone'],
            $values['taxCode'],
            $edited ? 'manual' : $default->signerType,
            $edited ? null : $default->signerRef,
        );
    }
}
