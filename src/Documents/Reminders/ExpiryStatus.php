<?php

namespace Unico\Core\Documents\Reminders;

/** Stato dello scadenziario per i controlli periodici delle app (unicobpm lo traduce nel suo CheckStatus). */
final class ExpiryStatus
{
    public const OK = 'ok';

    public const REGULAR = 'regular';

    public const WARNING = 'warning';

    public const ALERT = 'alert';

    public function __construct(
        public readonly int $count,
        public readonly string $severity,
        public readonly string $details = '',
    ) {}
}
