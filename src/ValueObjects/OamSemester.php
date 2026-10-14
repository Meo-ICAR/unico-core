<?php

namespace Unico\Core\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Semestre OAM (gennaio-giugno oppure luglio-dicembre) e i suoi estremi temporali.
 */
class OamSemester
{
    public readonly CarbonImmutable $start;

    public readonly CarbonImmutable $end;

    public function __construct(
        public readonly int $year,
        public readonly int $semesterNumber,
    ) {
        if (! in_array($semesterNumber, [1, 2], true)) {
            throw new InvalidArgumentException("Semestre non valido: {$semesterNumber}. Ammessi 1 o 2.");
        }

        if ($semesterNumber === 1) {
            $this->start = CarbonImmutable::create($year, 1, 1, 0, 0, 0);
            $this->end = CarbonImmutable::create($year, 6, 30, 23, 59, 59);
        } else {
            $this->start = CarbonImmutable::create($year, 7, 1, 0, 0, 0);
            $this->end = CarbonImmutable::create($year, 12, 31, 23, 59, 59);
        }
    }

    public static function current(?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();

        return $now->month <= 11
            ? new self($now->year, 1)
            : new self($now->year, 2);
    }

    public static function getInBaseAlMeseCorrente(): self
    {
        return self::current();
    }

    public static function fromPeriod(string $period): self
    {
        if (! preg_match('/^(\d{4})(\d{2})$/', $period, $m)) {
            throw new InvalidArgumentException("Periodo non valido: {$period}. Atteso formato YYYYMM.");
        }

        $month = (int) $m[2];

        return new self((int) $m[1], $month <= 6 ? 1 : 2);
    }

    public function period(): string
    {
        return sprintf('%04d%02d', $this->year, $this->semesterNumber === 1 ? 6 : 12);
    }
}
