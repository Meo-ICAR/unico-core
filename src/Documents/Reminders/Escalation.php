<?php

namespace Unico\Core\Documents\Reminders;

/**
 * Grado (severity) di un sollecito: il peggiore tra quello per la scadenza e quello per i solleciti già inviati.
 *  - scadenza: giorni mancanti sotto `days.alert` → alert (di norma già scaduto), fino a `days.warning` → warning,
 *    fino a `days.regular` → regular, altrimenti ok;
 *  - solleciti già inviati: da `sent.regular` in su regular, da `sent.warning` warning, da `sent.alert` alert.
 * Le soglie sono in `unico-core.documents.reminders.escalation`.
 */
class Escalation
{
    private const ORDER = ['ok', 'regular', 'warning', 'alert'];

    public static function severity(int $daysUntilExpiry, int $remindersSent): string
    {
        $byDays = match (true) {
            $daysUntilExpiry < (int) self::setting('days.alert', 0) => 'alert',
            $daysUntilExpiry <= (int) self::setting('days.warning', 7) => 'warning',
            $daysUntilExpiry <= (int) self::setting('days.regular', 30) => 'regular',
            default => 'ok',
        };

        $bySent = match (true) {
            $remindersSent >= (int) self::setting('sent.alert', 3) => 'alert',
            $remindersSent >= (int) self::setting('sent.warning', 2) => 'warning',
            $remindersSent >= (int) self::setting('sent.regular', 1) => 'regular',
            default => 'ok',
        };

        return array_search($byDays, self::ORDER, true) >= array_search($bySent, self::ORDER, true) ? $byDays : $bySent;
    }

    private static function setting(string $key, int $default): int
    {
        return (int) config("unico-core.documents.reminders.escalation.{$key}", $default);
    }
}
