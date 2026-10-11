<?php

namespace Unico\Core\Documents\Reminders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;
use Unico\Core\Documents\Reminders\Contracts\RecipientResolver;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentReminder;
use Unico\Core\Enums\MailType;
use Unico\Core\Mail\EmailTemplateResolver;
use Unico\Core\Mail\OutgoingMailer;
use Unico\Core\Mail\SenderAccountResolver;
use Unico\Core\Mail\ThreadId;
use Unico\Core\Models\EmailTemplate;

/**
 * Scadenziario e solleciti dei documenti (unione di unicooam e unicoloan). Sono da sollecitare:
 * i documenti monitorati in scadenza nella finestra, quelli richiesti e non arrivati, i respinti e
 * quelli caricati con anomalie tecniche. Le vecchie versioni (sostituite o rinnovate) restano fuori.
 * I solleciti sono raggruppati per soggetto, uno per soglia di giorni (`document_types.notify_days_before`).
 */
class DocumentReminderService
{
    public function __construct(private RecipientResolver $recipients) {}

    /** @return Builder<Document> */
    public function scheduleQuery(): Builder
    {
        $windowDays = (int) $this->setting('window_days', 90);
        $resendAfter = (int) $this->setting('resend_after_days', 5);
        $until = now()->addDays($windowDays)->toDateString();

        $query = Document::query()
            ->with(['documentType', 'documentable'])
            ->where(function (Builder $query) use ($until, $resendAfter) {
                $query->where(function (Builder $expiring) use ($until, $resendAfter) {
                    $expiring->where('is_monitored', true)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', $until)
                        ->whereNotIn('status', [DocumentStatus::REJECTED->value, DocumentStatus::NA->value])
                        ->where(fn (Builder $q) => $q->whereNull('last_sent_at')->orWhere('last_sent_at', '<', now()->subDays($resendAfter)));
                })
                    ->orWhere('status', DocumentStatus::PENDING->value)
                    ->orWhere('status', DocumentStatus::REJECTED->value)
                    ->orWhere(function (Builder $anomalies) {
                        $anomalies->where('status', DocumentStatus::UPLOADED->value)->whereExists(function ($analysis) {
                            $analysis->selectRaw('1')->from('document_analyses as a')
                                ->whereColumn('a.document_id', 'documents.id')
                                ->where('a.kind', 'verification')
                                ->where('a.outcome', 'failed')
                                ->whereRaw('a.id = (select max(x.id) from document_analyses as x where x.document_id = a.document_id and x.kind = ?)', ['verification']);
                        });
                    });
            });

        $this->excludeSupersededVersions($query);

        return $query->orderBy('expires_at');
    }

    /**
     * Esclude le vecchie versioni: quelle già sostituite (`replaced_by_id`) o scadute per rinnovo, quelle per cui esiste un
     * documento più recente dello stesso tipo sullo stesso soggetto (a pari emissione prevale la scadenza più lontana) e quelle
     * rinnovate da un altro tipo (`document_types.renewed_by_id`). Una versione più recente respinta, illeggibile o N/A non
     * sostituisce la precedente.
     */
    private function excludeSupersededVersions(Builder $query): void
    {
        $ignored = [DocumentStatus::REJECTED->value, DocumentStatus::UNREADABLE->value, DocumentStatus::NA->value];

        $query
            ->whereNull('documents.replaced_by_id')
            ->where('documents.status', '!=', DocumentStatus::EXPIRED->value)
            ->whereNotExists(function ($newer) use ($ignored): void {
                $newer->selectRaw('1')
                    ->from('documents as newer')
                    ->whereColumn('newer.documentable_type', 'documents.documentable_type')
                    ->whereColumn('newer.documentable_id', 'documents.documentable_id')
                    ->whereColumn('newer.document_type_id', 'documents.document_type_id')
                    ->whereColumn('newer.id', '!=', 'documents.id')
                    ->whereNull('newer.deleted_at')
                    ->whereNotNull('newer.emitted_at')
                    ->whereNotNull('documents.emitted_at')
                    ->where(function ($later): void {
                        $later->whereColumn('newer.emitted_at', '>', 'documents.emitted_at')
                            ->orWhere(function ($tie): void {
                                $tie->whereColumn('newer.emitted_at', '=', 'documents.emitted_at')
                                    ->whereNotNull('newer.expires_at')
                                    ->whereNotNull('documents.expires_at')
                                    ->whereColumn('newer.expires_at', '>', 'documents.expires_at');
                            });
                    })
                    ->whereNotIn('newer.status', $ignored);
            })
            ->whereNotExists(function ($renewer) use ($ignored): void {
                $renewer->selectRaw('1')
                    ->from('documents as renewer')
                    ->whereRaw('renewer.document_type_id = (select dt.renewed_by_id from document_types as dt where dt.id = documents.document_type_id)')
                    ->whereColumn('renewer.documentable_type', 'documents.documentable_type')
                    ->whereColumn('renewer.documentable_id', 'documents.documentable_id')
                    ->whereNull('renewer.deleted_at')
                    ->whereNotNull('renewer.emitted_at')
                    ->whereNotNull('documents.emitted_at')
                    ->whereColumn('renewer.emitted_at', '>=', 'documents.emitted_at')
                    ->whereNotIn('renewer.status', $ignored);
            });
    }

    /**
     * Documenti monitorati scaduti o in scadenza entro `$windowDays`. Il grado dipende dal più urgente:
     * scaduto = alert, entro 7 giorni = warning, oltre = regular. Non invia nulla.
     */
    public function expiryStatus(int $windowDays = 30): ExpiryStatus
    {
        $documents = Document::query()
            ->with('documentType')
            ->where('is_monitored', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($windowDays)->toDateString())
            ->whereNotIn('status', [DocumentStatus::REJECTED->value, DocumentStatus::NA->value])
            ->whereNull('replaced_by_id')
            ->orderBy('expires_at')
            ->get();

        if ($documents->isEmpty()) {
            return new ExpiryStatus(0, ExpiryStatus::OK);
        }

        $mostUrgent = $this->daysUntilExpiry($documents->first());

        $severity = match (true) {
            $mostUrgent < 0 => ExpiryStatus::ALERT,
            $mostUrgent <= 7 => ExpiryStatus::WARNING,
            default => ExpiryStatus::REGULAR,
        };

        $details = $documents->take(20)->map(fn (Document $d): string => sprintf(
            '- %s (%s) — %s%s',
            $d->name,
            $d->documentType?->name ?? 'Documento',
            $this->daysUntilExpiry($d) < 0 ? 'SCADUTO il ' : 'scade il ',
            $d->expires_at->format('d/m/Y'),
        ))->implode("\n");

        if ($documents->count() > 20) {
            $details .= "\n... e altri ".($documents->count() - 20).' documenti.';
        }

        return new ExpiryStatus($documents->count(), $severity, $details);
    }

    /**
     * Documenti già scaduti nella tabella dello scadenziario (da `ScheduleSynchronizer`). Il grado dipende da quanto è vecchia
     * la scadenza più in ritardo: oltre 30 giorni alert, oltre 15 warning, oltre 7 regular.
     */
    public function expiredStatus(int $maxListed = 100): ExpiryStatus
    {
        $expired = \Unico\Core\Models\DocumentSchedule::query()
            ->whereDate('expires_at', '<', now()->toDateString())
            ->orderBy('expires_at')
            ->get();

        if ($expired->isEmpty()) {
            return new ExpiryStatus(0, ExpiryStatus::OK, 'Nessun documento scaduto.');
        }

        $overdue = fn ($row): int => (int) $row->expires_at->startOfDay()->diffInDays(now()->startOfDay());
        $oldest = $overdue($expired->first());

        $list = $expired->take($maxListed)->map(fn ($row): string => sprintf(
            '- %s — %s (%s): scaduto il %s (%d giorni fa)',
            $row->entity_name, $row->document_name, $row->document_type_name, $row->expires_at->format('d/m/Y'), $overdue($row),
        ))->implode("\n");

        if ($expired->count() > $maxListed) {
            $list .= "\n... e altri ".($expired->count() - $maxListed).' documenti.';
        }

        $severity = match (true) {
            $oldest > 30 => ExpiryStatus::ALERT,
            $oldest > 15 => ExpiryStatus::WARNING,
            $oldest > 7 => ExpiryStatus::REGULAR,
            default => ExpiryStatus::OK,
        };

        return new ExpiryStatus($expired->count(), $severity, "{$expired->count()} documenti scaduti, il più vecchio da {$oldest} giorni.\n".$list);
    }

    /** @return Collection<string, Collection<int, Document>> raggruppati per `alias|id` del soggetto */
    public function groupedDocuments(?Builder $query = null): Collection
    {
        return ($query ?? $this->scheduleQuery())->get()->groupBy(
            fn (Document $d): string => $d->documentable_type.'|'.$d->documentable_id
        );
    }

    /** @return array{sent: int, skipped: int, failed: int, groups: int} */
    public function sendReminders(bool $onlyDueToday = true, ?string $groupKey = null, ?string $app = null): array
    {
        $stats = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'groups' => 0];

        if (! EmailTemplate::query()->where('code', $this->setting('template_code', 'DOC_EXPIRING'))->where('is_active', true)->exists()) {
            return $stats;
        }

        $groups = $this->groupedDocuments();

        if ($groupKey !== null) {
            $groups = $groups->only([$groupKey]);
        }

        foreach ($groups as $documents) {
            $due = $documents->filter(fn (Document $d) => $this->shouldRemind($d, $onlyDueToday))->values();

            if ($due->isEmpty()) {
                $stats['skipped'] += $documents->count();

                continue;
            }

            $stats['groups']++;
            $draft = $this->compose($due, $app);

            if ($draft->template === null || blank($draft->recipientEmail)) {
                $stats['skipped'] += $due->count();

                continue;
            }

            $result = $this->send($draft);
            $stats['sent'] += $result['sent'];
            $stats['failed'] += $result['failed'];
        }

        return $stats;
    }

    /**
     * Compone il sollecito per i documenti di un soggetto: destinatario, grado (severity), template e casella mittente.
     *  - grado: il peggiore tra scadenza più vicina e solleciti già inviati (`Escalation`);
     *  - template: `template_code` della company dei documenti e dell'app, del grado giusto (`EmailTemplateResolver`);
     *  - mittente: la casella di tipo «sollecito» della company (`SenderAccountResolver`); senza, parte dal mailer di default.
     *
     * @param  Collection<int, Document>  $documents  documenti dello stesso soggetto
     */
    public function compose(Collection $documents, ?string $app = null): ReminderDraft
    {
        $app ??= config('unico-core.app_identifier');
        $first = $documents->first();
        $recipient = $this->recipients->resolveForDocument($first);
        $severity = Escalation::severity(
            (int) $documents->map(fn (Document $d): int => $this->daysUntilExpiry($d))->min(),
            (int) $documents->max('reminders_count'),
        );
        $template = app(EmailTemplateResolver::class)->resolve((string) $this->setting('template_code', 'DOC_EXPIRING'), $first->company_id, $app, MailType::Sollecito, $severity);
        $rendered = $template === null ? ['subject' => '', 'body' => ''] : $this->renderGroupEmail($template, $recipient['name'], $documents);

        return new ReminderDraft(
            $documents,
            $recipient['name'],
            $recipient['email'],
            $severity,
            $template,
            app(SenderAccountResolver::class)->resolve($first->company_id, MailType::Sollecito),
            $rendered['subject'],
            $rendered['body'],
        );
    }

    /**
     * Invia il sollecito composto (con oggetto e corpo eventualmente cambiati dall'utente) e registra l'esito per ogni documento.
     * Opzioni:
     *  - `demo_to`: indirizzo a cui deviare l'invio (prova): oggetto con [DEMO], avviso in testa al corpo, nessun conteggio dei solleciti;
     *  - `cc`: indirizzo in copia (ignorato in demo);
     *  - `attachments`: file da allegare (`Media` o percorsi);
     *  - `reply_to`: [indirizzo, nome] per le risposte (di default quello della casella o di `mail.reply_to`).
     * La risposta si ricollega al documento: l'email porta `In-Reply-To`/`References` `<doc-{id}@dominio>` (vedi `Mail\ThreadId`).
     *
     * @param  array{demo_to?: ?string, cc?: ?string, attachments?: list<mixed>, reply_to?: array{0: string, 1?: ?string}}  $options
     * @return array{sent: int, failed: int}
     */
    public function send(ReminderDraft $draft, ?string $subject = null, ?string $body = null, array $options = []): array
    {
        $demoTo = $options['demo_to'] ?? null;
        $email = (string) ($demoTo ?: $draft->recipientEmail);
        $subject ??= $draft->subject;
        $body ??= $draft->body;

        if ($demoTo) {
            $subject = '[DEMO] '.$subject;
            $body = '<p><strong>Modalità demo attiva:</strong> l\'email di sollecito sarebbe stata inviata a <em>'.e((string) $draft->recipientEmail).'</em>, ma viene inviata a <em>'.e($demoTo).'</em> invece.</p><hr>'.$body;
        }

        $mailable = new ReminderMail($subject, $body, (array) ($options['attachments'] ?? []));
        $this->prepareThreadAndReplyTo($mailable, $draft, $options);
        $result = ['sent' => 0, 'failed' => 0];

        try {
            if ($draft->sender !== null) {
                app(OutgoingMailer::class)->send($draft->sender, $email, $mailable);
            } else {
                $pending = Mail::to($email);
                $cc = $demoTo ? null : ($options['cc'] ?? null);
                ($cc !== null && $cc !== '' ? $pending->cc($cc) : $pending)->send($mailable);
            }

            foreach ($draft->documents as $document) {
                $demoTo ? null : $this->recordReminder($document, $email);
                $result['sent']++;
            }
        } catch (Throwable $e) {
            foreach ($draft->documents as $document) {
                $demoTo ? null : $this->recordFailedReminder($document, $email, $e->getMessage());
                $result['failed']++;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $options */
    private function prepareThreadAndReplyTo(ReminderMail $mailable, ReminderDraft $draft, array $options): void
    {
        $thread = ThreadId::forDocument($draft->documents->first()->id);
        $mailable->withSymfonyMessage(function ($message) use ($thread): void {
            $message->getHeaders()->addTextHeader('In-Reply-To', $thread);
            $message->getHeaders()->addTextHeader('References', $thread);
        });

        $replyTo = $options['reply_to'] ?? null;

        if ($replyTo === null && filled(config('mail.reply_to.address'))) {
            $replyTo = [config('mail.reply_to.address'), config('mail.reply_to.name')];
        }

        if ($replyTo !== null && filled($replyTo[0])) {
            $mailable->replyTo($replyTo[0], $replyTo[1] ?? null);
        }
    }

    public function shouldRemind(Document $document, bool $onlyDueToday = true): bool
    {
        // Documenti assenti o con anomalia (senza scadenza): sollecito ripetuto finché non sono a posto.
        if ($document->expires_at === null) {
            return ! $onlyDueToday
                || $document->last_sent_at === null
                || $document->last_sent_at->lt(now()->subDays((int) $this->setting('missing_reminder_days', 7)));
        }

        if (! $onlyDueToday) {
            return true;
        }

        $days = $this->daysUntilExpiry($document);

        return in_array($days, $this->notifyThresholds($document), true) && ! $this->alreadySent($document, $days);
    }

    public function daysUntilExpiry(Document $document): int
    {
        if ($document->expires_at === null) {
            return 0;
        }

        return (int) now()->startOfDay()->diffInDays($document->expires_at->startOfDay(), false);
    }

    /** @return list<int> */
    public function notifyThresholds(Document $document): array
    {
        $configured = $document->documentType?->notify_days_before;

        if (is_array($configured) && $configured !== []) {
            return array_map('intval', $configured);
        }

        return array_map('intval', (array) $this->setting('default_notify_days_before', [30, 15, 7, 1, 0]));
    }

    /**
     * Segnaposto del template: {agente_nome}, {documento_nome}, {data_scadenza}, {n_documenti}, {elenco_documenti}.
     * Senza {elenco_documenti} l'elenco è accodato al corpo.
     *
     * @param  Collection<int, Document>  $documents
     * @return array{subject: string, body: string}
     */
    public function renderGroupEmail(EmailTemplate $template, string $recipientName, Collection $documents): array
    {
        $subject = $this->renderText($template->subject, $recipientName, $documents);
        $body = $this->renderText($template->body, $recipientName, $documents, appendList: true);

        if ($documents->count() > 1) {
            $subject = 'Sollecito scadenze documenti ('.$documents->count().')';
        }

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * Sostituisce i segnaposto in un testo qualsiasi (anche quello cambiato dall'utente nell'anteprima).
     * `{elenco_documenti}` diventa l'elenco HTML; con `$appendList` e senza il segnaposto l'elenco si accoda al testo.
     *
     * @param  Collection<int, Document>  $documents
     */
    public function renderText(string $text, string $recipientName, Collection $documents, bool $appendList = false): string
    {
        $list = '<ul>'.$documents->map(function (Document $d): string {
            $when = $d->expires_at === null ? 'da fornire' : ($this->daysUntilExpiry($d) < 0 ? 'SCADUTO' : $d->expires_at->format('d/m/Y'));

            return sprintf('<li><strong>%s</strong> (%s) — scadenza: %s</li>', e($d->name), e($d->documentType?->name ?? 'Documento'), e($when));
        })->implode('').'</ul>';

        $first = $documents->first();
        $single = $documents->count() === 1;

        $text = strtr($text, [
            '{agente_nome}' => e($recipientName),
            '{documento_nome}' => $single ? e((string) $first->name) : $documents->count().' documenti',
            '{data_scadenza}' => $single ? ($first->expires_at?->format('d/m/Y') ?? '—') : 'vedi elenco',
            '{n_documenti}' => (string) $documents->count(),
        ]);

        if (Str::contains($text, '{elenco_documenti}')) {
            return str_replace('{elenco_documenti}', $list, $text);
        }

        return $appendList ? $text.$list : $text;
    }

    private function alreadySent(Document $document, int $daysBefore): bool
    {
        return DocumentReminder::query()
            ->where('document_id', $document->id)
            ->where('days_before', $daysBefore)
            ->where('status', 'sent')
            ->exists();
    }

    private function recordReminder(Document $document, string $email): void
    {
        DocumentReminder::query()->updateOrCreate(
            ['document_id' => $document->id, 'days_before' => max(0, $this->daysUntilExpiry($document))],
            ['recipient_email' => $email, 'status' => 'sent', 'error_message' => null, 'sent_at' => now()],
        );

        $attributes = ['reminders_count' => (int) $document->reminders_count + 1, 'last_sent_at' => now()];

        // Provvisorio/in attesa dopo il sollecito; non per i documenti assenti o con anomalia, che restano da risolvere.
        if ($document->expires_at !== null) {
            $attributes['status'] = DocumentStatus::PROVISIONAL;
        }

        $document->update($attributes);
    }

    private function recordFailedReminder(Document $document, string $email, string $message): void
    {
        DocumentReminder::query()->updateOrCreate(
            ['document_id' => $document->id, 'days_before' => max(0, $this->daysUntilExpiry($document))],
            ['recipient_email' => $email, 'status' => 'failed', 'error_message' => mb_substr($message, 0, 500), 'sent_at' => now()],
        );
    }

    private function setting(string $key, mixed $default): mixed
    {
        return config("unico-core.documents.reminders.{$key}", $default);
    }
}
