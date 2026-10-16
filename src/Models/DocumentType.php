<?php

namespace Unico\Core\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use Unico\Core\Concerns\UsesCoreConnection;
use Unico\Core\MorphMap;

/**
 * Catalogo dei tipi documento: a chi si applica, quanto vale, come si verifica.
 * `company_id` nullo = tipo globale; valorizzato = tipo specifico di un tenant.
 */
class DocumentType extends Model
{
    use SoftDeletes, UsesCoreConnection;

    public const SOURCE_CLIENTE = 'cliente';

    public const SOURCE_API = 'api';

    public const SOURCE_TEMPLATE = 'template';

    public const SOURCE_STAMPA = 'stampa';

    /** @var array<string, string> come si ottiene il documento */
    public const SOURCES = [
        self::SOURCE_CLIENTE => 'Lo fornisce il cliente',
        self::SOURCE_API => 'Richiesto via API',
        self::SOURCE_TEMPLATE => 'Template inviato al cliente',
        self::SOURCE_STAMPA => 'Stampato con i nostri dati',
    ];

    /** @var array<string, string> a quale cliente si applica */
    public const CLIENT_SCOPES = [
        'persona_fisica' => 'Persona fisica',
        'persona_giuridica' => 'Persona giuridica',
        'entrambi' => 'Entrambi',
    ];

    protected $table = 'document_types';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (DocumentType $type): void {
            foreach (['signer_model', 'approver_model'] as $column) {
                $alias = $type->{$column};

                if (filled($alias) && ! isset(MorphMap::all()[$alias]) && Relation::getMorphedModel($alias) === null) {
                    throw new InvalidArgumentException("{$column}: alias «{$alias}» non presente in MorphMap.");
                }
            }

            // Chi firma implica che il documento va firmato; se va firmato e non si dice chi, firma il cliente.
            if (filled($type->signer_model)) {
                $type->is_signed = true;
            } elseif ($type->is_signed) {
                $type->signer_model = 'client';
            }

            // Un servizio API rende il documento richiedibile via API; i vecchi flag seguono la fonte.
            if (filled($type->api_service) && blank($type->source)) {
                $type->source = self::SOURCE_API;
            }

            if (filled($type->source)) {
                $type->is_template = in_array($type->source, [self::SOURCE_TEMPLATE, self::SOURCE_STAMPA], true);
                $type->nature = $type->source === self::SOURCE_STAMPA ? 'template_fillable' : ($type->nature === 'compliance' ? 'compliance' : 'incoming');
            }
        });
    }

    /** Il documento si applica a un cliente persona fisica (true) o giuridica (false). */
    public function appliesToPerson(bool $isPerson): bool
    {
        return match ($this->client_scope) {
            'persona_fisica' => $isPerson,
            'persona_giuridica' => ! $isPerson,
            default => true,
        };
    }

    protected function casts(): array
    {
        return [
            'is_person' => 'boolean',
            'is_company' => 'boolean',
            'is_employee' => 'boolean',
            'is_agent' => 'boolean',
            'is_principal' => 'boolean',
            'is_client' => 'boolean',
            'is_practice' => 'boolean',
            'is_signed' => 'boolean',
            'is_monitored' => 'boolean',
            'is_sensible' => 'boolean',
            'is_template' => 'boolean',
            'is_stored' => 'boolean',
            'is_versioned' => 'boolean',
            'is_end_month' => 'boolean',
            'is_ai_abstract' => 'boolean',
            'is_ai_check' => 'boolean',
            'allow_auto_verification' => 'boolean',
            'notify_days_before' => 'array',
            'checks' => 'array',
            'priority' => 'integer',
            'duration' => 'integer',
            'min_confidence' => 'integer',
            'retention_years' => 'integer',
            'training_hours' => 'integer',
        ];
    }

    /**
     * Data di scadenza di un documento emesso in $emittedAt.
     *
     * Null se il tipo non è monitorato, non ha una durata o l'unità è sconosciuta.
     * L'unità `hours` è ammessa; poiché la scadenza è una data, l'ora viene troncata.
     *
     * @param  bool  $forceEndMonth  Arrotonda a fine mese anche se il tipo non lo prevede
     *                               (flag `is_end_month` del singolo documento).
     */
    public function durationCalculate(DateTimeInterface|string|null $emittedAt, bool $forceEndMonth = false): ?Carbon
    {
        if (blank($emittedAt) || ! $this->is_monitored || ! $this->duration) {
            return null;
        }

        $base = Carbon::parse($emittedAt);

        $expiration = match ($this->duration_unit) {
            'hours' => $base->copy()->addHours($this->duration),
            'days' => $base->copy()->addDays($this->duration),
            'months' => $base->copy()->addMonths($this->duration),
            'years' => $base->copy()->addYears($this->duration),
            default => null,
        };

        if ($expiration && ($this->is_end_month || $forceEndMonth)) {
            $expiration = $expiration->endOfMonth();
        }

        return $expiration?->startOfDay();
    }

    /** Modulo PDF compilabile associato al tipo (con gli slot di firma). */
    /** Istituto (banca) a cui è legato il documento, se è specifico di una banca. */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'clienti_id');
    }

    /**
     * Tipi che valgono per quella banca: quelli senza banca (per tutte) e quelli della banca della pratica.
     * Con la banca non ancora scelta restano solo quelli validi per tutte.
     *
     * @param  Builder<DocumentType>  $query
     */
    public function scopeForBank(Builder $query, int|string|null $clientiId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('clienti_id')->when($clientiId !== null, fn (Builder $w) => $w->orWhere('clienti_id', $clientiId)));
    }

    /** Il documento vale per la pratica di quella banca? */
    public function appliesToBank(int|string|null $clientiId): bool
    {
        return $this->clienti_id === null || ($clientiId !== null && (int) $this->clienti_id === (int) $clientiId);
    }

    public function pdfModule(): HasOne
    {
        return $this->hasOne(PdfModule::class, 'document_type_id');
    }

    /** Documenti di questo tipo. */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'document_type_id');
    }

    /** Tipo che rinnova questo (es. il certificato nuovo che sostituisce quello in scadenza). */
    /** Istituto a cui è legato il documento: se valorizzato serve solo sulle pratiche di quella banca. */
    public function clienti(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'clienti_id');
    }

    public function renewedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewed_by_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
