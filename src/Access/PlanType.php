<?php

namespace Unico\Core\Access;

/**
 * Piano (licenza) di una company per un'app. Il periodo di prova (`Trial`) sblocca tutto come `Full` finché non scade
 * (`company_modules.trial_ends_at`); poi vale il piano di ripiego (`unico-core.access.trial_expired_plan`).
 */
enum PlanType: string
{
    case Base = 'BASE';
    case Medium = 'MEDIUM';
    case Full = 'FULL';
    case Trial = 'TRIAL';

    public static function parse(mixed $value, self $default): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return self::tryFrom(strtoupper(trim((string) $value))) ?? $default;
    }

    public function level(): int
    {
        return match ($this) {
            self::Base => 1,
            self::Medium => 2,
            self::Full, self::Trial => 3,
        };
    }

    /** Il piano copre una risorsa che richiede almeno `$minPlan` (un valore non riconosciuto vale BASE). */
    public function covers(?string $minPlan): bool
    {
        return $this->level() >= self::parse($minPlan, self::Base)->level();
    }
}
