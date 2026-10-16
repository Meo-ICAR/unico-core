<?php

namespace Unico\Core\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Unico\Core\Concerns\UsesCoreConnection;
use Unico\Core\Enums\DocumentStatus;

/**
 * Il documento concreto, legato a qualsiasi record tramite il morph `documentable`.
 * I file stanno nella media collection `documents` (disco privato); la versione
 * firmata, quando c'è, nella collection `signed`.
 */
class Document extends Model implements HasMedia
{
    use InteractsWithMedia, SoftDeletes, UsesCoreConnection;

    public const COLLECTION = 'documents';

    public const COLLECTION_SIGNED = 'signed';

    protected $table = 'documents';

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'caricato',
        'spatie_collection' => 'default',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'metadata' => 'array',
            'is_template' => 'boolean',
            'is_signed' => 'boolean',
            'is_printable' => 'boolean',
            'is_unique' => 'boolean',
            'is_end_month' => 'boolean',
            'is_monitored' => 'boolean',
            'emitted_at' => 'date',
            'expires_at' => 'date',
            'delivered_at' => 'datetime',
            'signed_at' => 'datetime',
            'verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'ai_confidence_score' => 'integer',
            'reminders_count' => 'integer',
            'training_hours' => 'integer',
        ];
    }

    public function registerMediaCollections(): void
    {
        $disk = config('unico-core.documents_disk');

        $this->addMediaCollection(self::COLLECTION)->useDisk($disk);
        $this->addMediaCollection(self::COLLECTION_SIGNED)->useDisk($disk)->singleFile();
    }

    protected static function booted(): void
    {
        static::saving(function (Document $document): void {
            $document->stampAudit();
            $document->applyStatusRules();
            $document->computeExpiry();
        });
    }

    /**
     * Compila gli utenti di creazione e modifica, se c'è un utente autenticato.
     * Un'app con utenti propri (non quelli della tabella `users` del core) spegne `unico-core.audit.stamp_users`:
     * i suoi id non esistono nel core e violerebbero le chiavi esterne.
     */
    protected function stampAudit(): void
    {
        $userId = config('unico-core.audit.stamp_users', true) ? Auth::id() : null;

        if ($userId === null) {
            return;
        }

        if (! $this->exists && $this->created_by === null) {
            $this->created_by = $userId;
        }

        if (! $this->isDirty('updated_by')) {
            $this->updated_by = $userId;
        }
    }

    /**
     * Un documento richiesto che ha già la data di emissione è da considerare approvato;
     * un documento respinto deve avere sempre una motivazione.
     */
    protected function applyStatusRules(): void
    {
        if ($this->status === DocumentStatus::PENDING && filled($this->emitted_at)) {
            $this->status = DocumentStatus::APPROVED;
        }

        if ($this->status === DocumentStatus::REJECTED) {
            $this->rejection_note = $this->rejection_note ?? 'Nessuna nota fornita.';
        }
    }

    /**
     * Calcola la scadenza dal tipo documento, ma solo se manca o se è cambiata la data
     * di emissione senza che qualcuno abbia toccato la scadenza: una scadenza inserita
     * a mano non viene mai sovrascritta.
     */
    protected function computeExpiry(): void
    {
        if (blank($this->emitted_at)) {
            return;
        }

        $emissionChanged = $this->exists && $this->isDirty('emitted_at') && ! $this->isDirty('expires_at');

        if (filled($this->expires_at) && ! $emissionChanged) {
            return;
        }

        $expiry = $this->documentType?->durationCalculate($this->emitted_at, (bool) $this->is_end_month);

        if ($expiry !== null) {
            $this->expires_at = $expiry;
        }
    }

    // --- Relazioni ---

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo($this->userModel(), 'user_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo($this->userModel(), 'uploaded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo($this->userModel(), 'verified_by');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(DocumentReminder::class, 'document_id');
    }

    /** Il documento che prende il posto di questo (rinnovo o versione firmata). */
    /** Il file che vale per il documento: la versione firmata se c'è, altrimenti l'originale. */
    public function currentMedia(): ?Media
    {
        return $this->getFirstMedia(self::COLLECTION_SIGNED) ?? $this->getFirstMedia(self::COLLECTION);
    }

    /** Si salva sempre l'alias stabile, anche se il record è caricato da una classe del pacchetto invece che da quella dell'app. */
    public function getMorphClass(): string
    {
        return 'document';
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(DocumentAnalysis::class, 'document_id');
    }

    /** L'ultima verifica tecnica del documento (`document_analyses.kind = verification`), se c'è. */
    public function latestVerification(): ?DocumentAnalysis
    {
        return $this->analyses()->where('kind', 'verification')->latest('id')->first();
    }

    /**
     * Documenti la cui ultima verifica tecnica ha l'esito indicato (failed, needs_review, passed).
     *
     * @param  list<string>  $outcomes
     */
    public function scopeWhereLatestVerification(Builder $query, array $outcomes): Builder
    {
        return $query->whereExists(function ($analysis) use ($outcomes): void {
            $analysis->selectRaw('1')->from('document_analyses as a')
                ->whereColumn('a.document_id', 'documents.id')
                ->where('a.kind', 'verification')
                ->whereIn('a.outcome', $outcomes)
                ->whereRaw('a.id = (select max(x.id) from document_analyses as x where x.document_id = a.document_id and x.kind = ?)', ['verification']);
        });
    }

    public function signatureRequests(): HasMany
    {
        return $this->hasMany(SignatureRequest::class, 'document_id');
    }

    public function latestSignatureRequest(): ?SignatureRequest
    {
        return $this->relationLoaded('signatureRequests')
            ? $this->signatureRequests->sortByDesc('id')->first()
            : $this->signatureRequests()->latest('id')->first();
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(static::class, 'replaced_by_id');
    }

    /** La nuova versione del documento (rinnovo). */
    public function newVersion(): BelongsTo
    {
        return $this->belongsTo(static::class, 'renewed_by');
    }

    /** Il requisito del plico da cui nasce il documento: task, fase, ruoli, chi firma. */
    public function documentRequirement(): BelongsTo
    {
        return $this->belongsTo(DocumentRequirement::class, 'document_requirement_id');
    }

    /** Chi ha firmato il documento. */
    public function signedBy(): MorphTo
    {
        return $this->morphTo('signed_by', 'signed_by_type', 'signed_by_id');
    }

    /** Chi lo ha approvato con la sua firma (vedi `document_types.approver_model`). */
    public function approvedBy(): MorphTo
    {
        return $this->morphTo('approved_by', 'approved_by_type', 'approved_by_id');
    }

    /** Il tipo prevede l'approvazione con firma e non è ancora stata data. */
    public function awaitsApproval(): bool
    {
        return filled($this->documentType?->approver_model) && $this->is_signed && $this->approved_at === null;
    }

    /**
     * Approvazione con firma di chi è indicato in `document_types.approver_model` (es. l'agente).
     *
     * @throws \InvalidArgumentException se l'approvatore non è del tipo previsto
     */
    public function approve(Model $approver): self
    {
        $expected = $this->documentType?->approver_model;

        if (blank($expected)) {
            throw new \InvalidArgumentException('Il tipo di documento non prevede un\'approvazione con firma.');
        }

        $alias = $approver->getMorphClass();

        if ($alias !== $expected && ! ($approver instanceof (Relation::getMorphedModel($expected) ?? \stdClass::class))) {
            throw new \InvalidArgumentException("Il documento va approvato da «{$expected}», non da «{$alias}».");
        }

        $this->forceFill(['approved_by_type' => $expected, 'approved_by_id' => (string) $approver->getKey(), 'approved_at' => now()])->save();

        return $this;
    }

    /** Il documento che questo ha sostituito. */
    public function replaces(): HasOne
    {
        return $this->hasOne(static::class, 'replaced_by_id');
    }

    /** Tipo documento che dovrebbe rinnovare questo, se previsto dal catalogo. */
    public function renewingType(): ?DocumentType
    {
        return $this->documentType?->renewedBy;
    }

    protected function userModel(): string
    {
        return config('unico-core.user_model') ?? User::class;
    }

    // --- Accessor ---

    /** URL effettivo del file remoto: `document_url`, altrimenti `metadata.web_url` (vecchi import). */
    protected function resolvedUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => filled($this->document_url)
            ? $this->document_url
            : (filled($this->metadata['web_url'] ?? null) ? $this->metadata['web_url'] : null));
    }

    /** Link di download autenticato (copia locale o URL remoto), solo se esiste un file. */
    protected function downloadUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->getFirstMedia(self::COLLECTION) || filled($this->resolved_url)
            ? route('documents.download', $this)
            : null);
    }

    // --- Scope ---

    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($days)->toDateString());
    }

    /** Documenti ancora validi come versione corrente: non sostituiti e non scaduti o revocati. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query
            ->whereNull('replaced_by_id')
            ->whereNotIn('status', array_map(fn (DocumentStatus $s) => $s->value, DocumentStatus::inactive()));
    }

    /** Per ogni tipo di documento, l'ultimo emesso entro la data indicata. */
    public function scopeLatestPerTypeUntil(Builder $query, DateTimeInterface|string $end): Builder
    {
        $end = $end instanceof DateTimeInterface ? $end->format('Y-m-d') : $end;

        return $query->where('emitted_at', '<=', $end)
            ->whereIn('id', function ($subquery) use ($end) {
                $subquery->select('id')
                    ->from('documents as d1')
                    ->where('d1.emitted_at', '<=', $end)
                    ->whereRaw('d1.emitted_at = (
                        SELECT MAX(d2.emitted_at)
                        FROM documents as d2
                        WHERE d2.document_type_id = d1.document_type_id
                        AND d2.emitted_at <= ?
                        AND d2.deleted_at IS NULL
                    )', [$end]);
            });
    }

    /**
     * Ultimo aggiornamento di ogni tipo entro la fine del semestre OAM.
     *
     * @param  object{end: DateTimeInterface|string}  $semester
     */
    public function scopePerSemestreOam(Builder $query, object $semester): Builder
    {
        return $this->scopeLatestPerTypeUntil($query, $semester->end);
    }

    // --- Operazioni ---

    /**
     * Rinnovo: crea il nuovo documento, segna questo come scaduto e sostituito.
     */
    public function renew(DateTimeInterface|string|null $emittedAt = null): self
    {
        return $this->getConnection()->transaction(function () use ($emittedAt): self {
            $new = static::create([
                'company_id' => $this->company_id,
                'documentable_type' => $this->documentable_type,
                'documentable_id' => $this->documentable_id,
                'document_type_id' => $this->document_type_id,
                'user_id' => $this->user_id,
                'document_requirement_id' => $this->document_requirement_id,
                'name' => $this->name,
                'doctype' => $this->doctype,
                'spatie_collection' => $this->spatie_collection ?? 'default',
                'description' => $this->description,
                'internal_notes' => $this->internal_notes,
                'status' => DocumentStatus::APPROVED,
                'is_monitored' => $this->is_monitored ?? false,
                'is_unique' => $this->is_unique ?? false,
                'is_end_month' => $this->is_end_month ?? false,
                'is_template' => false,
                'training_hours' => $this->training_hours,
                'training_organization' => $this->training_organization,
                'emitted_at' => $emittedAt ?? now(),
            ]);

            $this->update([
                'status' => DocumentStatus::EXPIRED,
                'replaced_by_id' => $new->id,
                'renewed_by' => $new->id,
                'metadata' => array_merge($this->metadata ?? [], [
                    'replaced_at' => now()->toIso8601String(),
                ]),
            ]);

            return $new;
        });
    }

    /**
     * Clona il documento, con gli allegati, per ogni documento aggiuntivo di un plico
     * (un file che contiene più documenti). I documenti del plico condividono
     * `metadata.bundle_id`.
     *
     * @param  array<int, array{document_type_id: int, emitted_at?: string|null, docnumber?: string|null}>  $rows
     * @return Collection<int, self>
     */
    public function createBundleCopies(array $rows): Collection
    {
        $types = collect($rows)->map(fn (array $row): DocumentType => DocumentType::query()->findOrFail($row['document_type_id']));

        return $this->getConnection()->transaction(function () use ($rows, $types): Collection {
            $bundleId = $this->metadata['bundle_id'] ?? (string) Str::uuid();

            $this->update(['metadata' => [...($this->metadata ?? []), 'bundle_id' => $bundleId]]);

            $attachments = $this->getMedia(self::COLLECTION);

            return $types->map(function (DocumentType $type, int $index) use ($rows, $bundleId, $attachments): self {
                $row = $rows[$index];

                $copy = static::create([
                    'company_id' => $this->company_id,
                    'documentable_type' => $this->documentable_type,
                    'documentable_id' => $this->documentable_id,
                    'user_id' => $this->user_id,
                    'document_type_id' => $type->id,
                    'name' => $type->name,
                    'doctype' => $type->doctype,
                    'docnumber' => $row['docnumber'] ?? null,
                    'is_monitored' => (bool) $type->is_monitored,
                    'emitted_at' => $row['emitted_at'] ?? null,
                    'document_url' => $this->document_url,
                    'metadata' => ['bundle_id' => $bundleId],
                ]);

                foreach ($attachments as $attachment) {
                    $attachment->copy($copy, self::COLLECTION, $attachment->disk);
                }

                return $copy;
            })->values();
        });
    }
}
