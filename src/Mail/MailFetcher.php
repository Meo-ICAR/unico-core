<?php

namespace Unico\Core\Mail;

use Carbon\Carbon;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;
use Unico\Core\Events\IncomingEmailReceived;
use Unico\Core\Mail\Contracts\EmailClassifier;
use Unico\Core\Mail\Contracts\ImapConnector;
use Unico\Core\Models\IncomingEmail;
use Unico\Core\Models\MailAccount;

/**
 * Scarica le email di una casella in `incoming_emails` e le allegati nella media collection
 * `email_attachments`. Non sa cosa farne: per ogni nuova email emette IncomingEmailReceived.
 */
class MailFetcher
{
    public function __construct(
        private ImapConnector $connections,
        private EmailClassifier $classifier,
        private Dispatcher $events,
    ) {}

    /** @return Collection<int, IncomingEmail> Le email nuove importate */
    public function fetch(MailAccount $account, ?int $limit = null): Collection
    {
        $limit ??= (int) config('unico-core.mail.limit', 50);

        $client = $this->connections->make($account);
        $client->connect();

        // Non ci si affida al flag «non letta»: una mail già aperta altrove verrebbe ignorata
        // per sempre. Si scansiona una finestra di date e la deduplica per message_id evita i doppioni.
        $since = $account->last_synced_at
            ? $account->last_synced_at->copy()->subDays((int) config('unico-core.mail.overlap_days', 2))
            : now()->subDays((int) config('unico-core.mail.initial_days', 30));

        $messages = $client->getFolder('INBOX')->messages()->since($since)->get();

        $imported = new Collection;

        foreach ($messages as $message) {
            if ($imported->count() >= $limit) {
                break;
            }

            $email = $this->import($account, $message);

            if ($email === null) {
                continue;
            }

            $imported->push($email);
            $message->setFlag('Seen');
            $this->announce($email);
        }

        $account->forceFill(['last_synced_at' => now()])->save();

        Log::info('Fetch casella completato', [
            'company_id' => $account->company_id,
            'mail_account_id' => $account->id,
            'imported' => $imported->count(),
        ]);

        return $imported;
    }

    private function import(MailAccount $account, object $message): ?IncomingEmail
    {
        $fromAddress = $message->getFrom()[0]->mail ?? null;

        if (! $fromAddress) {
            return null;
        }

        $messageId = $this->normalizeId((string) $message->getMessageId())
            ?: 'fallback-'.hash('sha256', $fromAddress.'|'.$message->getSubject().'|'.$this->messageDate($message)->toIso8601String());

        $exists = IncomingEmail::withTrashed()
            ->where('company_id', $account->company_id)
            ->where('message_id', $messageId)
            ->exists();

        if ($exists) {
            return null;
        }

        $references = implode(' ', array_map(
            fn ($r) => $this->normalizeId((string) $r),
            (array) $message->getReferences()->toArray()
        ));
        $inReplyTo = $this->normalizeId((string) $message->getInReplyTo());

        $email = new IncomingEmail([
            'company_id' => $account->company_id,
            'mail_account_id' => $account->id,
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo ?: null,
            'references' => $references ?: null,
            'thread_id' => IncomingEmail::deriveThreadId($references, $inReplyTo, $messageId),
            'from_email' => $fromAddress,
            'from_name' => mb_substr((string) ($message->getFrom()[0]->personal ?: $fromAddress), 0, 255),
            'to' => $this->addresses($message->getTo()),
            'cc' => $this->addresses($message->getCc()),
            'subject' => mb_substr($this->decodeSubject((string) $message->getSubject()) ?: '(Senza Oggetto)', 0, 255),
            'body_text' => $message->getTextBody() ?: null,
            'body_html' => $message->getHTMLBody() ?: null,
            'received_at' => $this->messageDate($message),
            'is_read' => false,
        ]);

        $email->classification = $this->classifier->classify($email);
        $email->save();

        $this->storeAttachments($message, $email);

        return $email;
    }

    /** Un errore in un ascoltatore non deve fermare la scansione: l'email è già salvata. */
    private function announce(IncomingEmail $email): void
    {
        try {
            $this->events->dispatch(new IncomingEmailReceived($email));
        } catch (Throwable $e) {
            Log::error('Ascoltatore di IncomingEmailReceived fallito', [
                'incoming_email_id' => $email->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function storeAttachments(object $message, IncomingEmail $email): void
    {
        if (! $message->hasAttachments()) {
            return;
        }

        foreach ($message->getAttachments() as $attachment) {
            $fileName = $this->safeAttachmentName((string) $attachment->getName());
            $tmp = tempnam(sys_get_temp_dir(), 'mail_att_');

            try {
                file_put_contents($tmp, $attachment->getContent());

                $email->addMedia($tmp)
                    ->usingFileName($fileName)
                    ->toMediaCollection(IncomingEmail::ATTACHMENTS);
            } catch (Throwable $e) {
                // Un allegato non archiviabile (es. oltre il limite di dimensione) non blocca la casella.
                Log::warning('Allegato email non archiviato', [
                    'incoming_email_id' => $email->id,
                    'file' => $fileName,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                @unlink($tmp);
            }
        }
    }

    /** Decodifica l'oggetto MIME (=?UTF-8?Q?...?=) rimasto in forma codificata. */
    private function decodeSubject(string $subject): string
    {
        return trim(@iconv_mime_decode($subject, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: $subject);
    }

    /** Decodifica il nome MIME, toglie i separatori di percorso e accorcia mantenendo l'estensione. */
    private function safeAttachmentName(string $name): string
    {
        $decoded = trim(str_replace(['/', '\\', "\0"], '-', iconv_mime_decode($name, 0, 'UTF-8') ?: $name));

        if ($decoded === '') {
            return 'allegato';
        }

        $extension = pathinfo($decoded, PATHINFO_EXTENSION);

        if (! preg_match('/^[A-Za-z0-9]{1,10}$/u', $extension)) {
            return mb_substr($decoded, 0, 120);
        }

        return mb_substr(pathinfo($decoded, PATHINFO_FILENAME), 0, 120).'.'.$extension;
    }

    /**
     * @param  object|array<int, object>|null  $addresses  Header To/Cc: un Attribute di webklex con `all()`, un array o un iterabile
     * @return array<int, array{email: ?string, name: ?string}>
     */
    private function addresses(mixed $addresses): array
    {
        if (is_object($addresses) && method_exists($addresses, 'all')) {
            $addresses = $addresses->all();
        } elseif (! is_array($addresses)) {
            $addresses = $addresses ? iterator_to_array($addresses) : [];
        }

        return array_values(array_map(fn ($a) => [
            'email' => $a->mail ?? null,
            'name' => $a->personal ?? null,
        ], $addresses));
    }

    private function messageDate(object $message): Carbon
    {
        try {
            return Carbon::parse((string) $message->getDate());
        } catch (Throwable) {
            return now();
        }
    }

    private function normalizeId(string $value): string
    {
        return mb_substr(trim($value, " \t\n\r\0\x0B<>"), 0, 255);
    }
}
