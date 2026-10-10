<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Unico\Core\Concerns\UsesCoreConnection;

/** Email scaricata da una casella. Gli allegati stanno nella collection `email_attachments`. */
class IncomingEmail extends Model implements HasMedia
{
    use InteractsWithMedia, SoftDeletes, UsesCoreConnection;

    public const ATTACHMENTS = 'email_attachments';

    protected $table = 'incoming_emails';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'received_at' => 'datetime',
            'is_read' => 'boolean',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::ATTACHMENTS)->useDisk(config('unico-core.documents_disk'));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    /** Record generato dall'email: reclamo, richiesta dell'interessato, pratica, processo. */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /** Altre email della stessa conversazione, esclusa questa, dalla più vecchia. */
    public function threadMessages(): Builder
    {
        return static::query()
            ->where('company_id', $this->company_id)
            ->where('thread_id', $this->thread_id ?: $this->message_id)
            ->whereKeyNot($this->getKey())
            ->orderBy('received_at');
    }

    /**
     * Corpo HTML senza head, stili, script e immagini: aprire l'email non carica risorse
     * esterne né pixel di tracciamento. Il risultato va comunque sanificato da chi lo mostra.
     */
    public function displayHtml(): ?string
    {
        $html = trim((string) $this->body_html);

        if ($html === '') {
            return null;
        }

        $html = preg_replace(
            ['#<head\b.*?</head>#is', '#<(style|script|title)\b.*?</\1>#is', '#<img\b[^>]*>#i'],
            ['', '', ''],
            $html,
        ) ?? $html;

        return trim($html) !== '' ? $html : null;
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }

    /** Thread dagli header: prima reference, altrimenti in_reply_to, altrimenti il message_id. */
    public static function deriveThreadId(?string $references, ?string $inReplyTo, string $messageId): string
    {
        $firstReference = trim(explode(' ', trim((string) $references))[0] ?? '');

        return $firstReference !== ''
            ? $firstReference
            : (trim((string) $inReplyTo) ?: $messageId);
    }
}
