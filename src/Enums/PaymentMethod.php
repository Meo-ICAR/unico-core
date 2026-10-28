<?php

namespace Unico\Core\Enums;

use Filament\Support\Contracts\HasLabel;

/** Modalità di pagamento delle rate (intervista preliminare). */
enum PaymentMethod: string implements HasLabel
{
    case Sdd = 'sdd';
    case BollettinoPostale = 'bollettino_postale';
    case Trattenuta = 'trattenuta';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sdd => 'SDD (addebito su conto corrente)',
            self::BollettinoPostale => 'Bollettino postale',
            self::Trattenuta => 'Trattenuta sullo stipendio / pensione',
        };
    }
}
