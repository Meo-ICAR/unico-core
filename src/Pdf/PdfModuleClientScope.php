<?php

namespace Unico\Core\Pdf;

use Filament\Support\Contracts\HasLabel;

/** A chi si applica un modulo PDF: persona fisica, giuridica, o entrambi. */
enum PdfModuleClientScope: string implements HasLabel
{
    case PersonaFisica = 'persona_fisica';
    case PersonaGiuridica = 'persona_giuridica';
    case Entrambi = 'entrambi';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::PersonaFisica => 'Persona fisica',
            self::PersonaGiuridica => 'Persona giuridica',
            self::Entrambi => 'Entrambi',
        };
    }

    public function matches(bool $isPerson): bool
    {
        return match ($this) {
            self::PersonaFisica => $isPerson,
            self::PersonaGiuridica => ! $isPerson,
            self::Entrambi => true,
        };
    }
}
