<?php

namespace Unico\Core\Documents\Reminders;

use Unico\Core\Documents\Reminders\Contracts\RecipientResolver;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentSchedule;

/** Ricalcola la tabella `document_schedules` (scadenziario) dai documenti da sollecitare. */
class ScheduleSynchronizer
{
    public function __construct(private DocumentReminderService $service, private RecipientResolver $recipients) {}

    /** @return int righe scritte */
    public function sync(): int
    {
        $rows = $this->service->scheduleQuery()->get()->map(fn (Document $d): array => [
            'document_id' => $d->id,
            'documentable_group_key' => $d->documentable_type.'|'.$d->documentable_id,
            'document_name' => (string) $d->name,
            'document_type_name' => $d->documentType?->name ?? '-',
            'entity_name' => $this->recipients->entityName($d),
            'documentable_type' => $d->documentable_type,
            'documentable_id' => $d->documentable_id,
            'expires_at' => $d->expires_at?->toDateString(),
            'days_until_expiry' => $this->service->daysUntilExpiry($d),
            'status' => $d->status instanceof \BackedEnum ? $d->status->value : (string) $d->status,
            'reminders_count' => (int) $d->reminders_count,
            'last_sent_at' => $d->last_sent_at,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        // delete e non truncate: il TRUNCATE chiude la transazione e per un attimo la lista sarebbe vuota.
        DocumentSchedule::query()->getConnection()->transaction(function () use ($rows): void {
            DocumentSchedule::query()->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DocumentSchedule::query()->insert($chunk);
            }
        });

        return count($rows);
    }
}
