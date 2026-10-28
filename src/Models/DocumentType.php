<?php

namespace Unico\Core\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
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
    /** Valori di `is_end_month` (anche sul documento): approssimazione della scadenza. */
    public const END_NONE = 0;

    public const END_MONTH = 1;

    public const END_YEAR = 2;

    /**
     * @var array<int, string>
     */
    public const END_OPTIONS = [self::END_NONE => 'Nessuna', self::END_MONTH => 'Fine mese', self::END_YEAR => 'Fine anno'];

    use SoftDeletes, UsesCoreConnection;

    public const SOURCE_CLIENTE = 'cliente';

    public const SOURCE_FORNITORE = 'fornitore';

    public const SOURCE_EMPLOYEE = 'employee';

    public const SOURCE_API = 'api';

    public const SOURCE_TEMPLATE = 'template';

    public const SOURCE_STAMPA = 'stampa';

    /** @var array<string, string> come si ottiene il documento */
    public const SOURCES = [
        self::SOURCE_CLIENTE => 'Lo fornisce il cliente',
        self::SOURCE_FORNITORE => 'Lo fornisce l\'agente / produttore',
        self::SOURCE_EMPLOYEE => 'Lo fornisce il dipendente',
        self::SOURCE_API => 'Richiesto via API',
        self::SOURCE_TEMPLATE => 'Template inviato al cliente',
        self::SOURCE_STAMPA => 'Stampato con i nostri dati',
    ];

    /** @var array<string, string> a quale cliente si applica */
    /**
     * Colonne tolte (vedi migration drop_superseded_document_type_columns): se un'app o un seeder le passa ancora, si traducono
     * nei campi nuovi e si scartano.
     */
    public const SUPERSEDED = [
        'is_person', 'nature', 'is_template',
        'is_client', 'is_agent', 'is_employee', 'is_principal', 'is_practice', 'document_typable',
        'priority', 'phase', 'cellposition',
    ];

    public const CLIENT_SCOPES = [
        'persona_fisica' => 'Persona fisica',
        'persona_giuridica' => 'Persona giuridica',
        'entrambi' => 'Entrambi',
    ];

    /** Gruppo (`codegroup`) dei documenti d'identità: carta d'identità, patente, passaporto... */
    public const CODEGROUP_IDENTITA = 'IDENTITA';

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
            // Togliere la firma toglie anche chi firma e chi approva.
            if ($type->isDirty('is_signed') && ! $type->is_signed) {
                $type->signer_model = null;
                $type->approver_model = null;
            } elseif (filled($type->signer_model)) {
                $type->is_signed = true;
            } elseif ($type->is_signed) {
                $type->signer_model = 'client';
            }

            // Assegnazioni dirette delle colonne superate: si traducono e si tolgono, altrimenti il salvataggio fallirebbe.
            $type->setRawAttributes(self::translateSuperseded($type->getAttributes()));

            // Un servizio API rende il documento richiedibile via API.
            if (filled($type->api_service) && blank($type->source)) {
                $type->source = self::SOURCE_API;
            }
        });
    }

    /**
     * Le colonne superate passate in un mass assignment (vecchie app, seeder) diventano `client_scope` e `source` prima che
     * Eloquent le scarti perché non esistono più.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function fill(array $attributes): static
    {
        return parent::fill(self::translateSuperseded($attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function translateSuperseded(array $attributes): array
    {
        if (blank($attributes['client_scope'] ?? null) && array_key_exists('is_person', $attributes)) {
            $attributes['client_scope'] = (bool) $attributes['is_person'] ? 'persona_fisica' : 'persona_giuridica';
        }

        if (blank($attributes['source'] ?? null) && (array_key_exists('is_template', $attributes) || array_key_exists('nature', $attributes))) {
            $attributes['source'] = match (true) {
                ($attributes['nature'] ?? null) === 'template_fillable' => self::SOURCE_STAMPA,
                (bool) ($attributes['is_template'] ?? false) => self::SOURCE_TEMPLATE,
                default => self::SOURCE_CLIENTE,
            };
        }

        return array_diff_key($attributes, array_flip(self::SUPERSEDED));
    }

    /** Forniamo noi il documento: template inviato al cliente o modulo stampato con i nostri dati. */
    public function getIsTemplateAttribute(): bool
    {
        return in_array($this->source, [self::SOURCE_TEMPLATE, self::SOURCE_STAMPA], true);
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
            'is_company' => 'boolean',
            'is_signed' => 'boolean',
            'is_monitored' => 'boolean',
            'is_sensible' => 'boolean',
            'is_stored' => 'boolean',
            'is_versioned' => 'boolean',
            'is_end_month' => 'integer',
            'is_ai_abstract' => 'boolean',
            'is_ai_check' => 'boolean',
            'allow_auto_verification' => 'boolean',
            'notify_days_before' => 'array',
            'checks' => 'array',
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
     * @param  int|bool  $forceRounding  Arrotondamento imposto dal singolo documento (`is_end_month` del documento, vedi `END_MONTH` e
     *                                   `END_YEAR`): vale il maggiore tra questo e quello del tipo. `true` equivale a fine mese.
     */
    public function durationCalculate(DateTimeInterface|string|null $emittedAt, int|bool $forceRounding = self::END_NONE): ?Carbon
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

        $rounding = max((int) $this->is_end_month, (int) $forceRounding);

        if ($expiration && $rounding >= self::END_YEAR) {
            $expiration = $expiration->endOfYear();
        } elseif ($expiration && $rounding === self::END_MONTH) {
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
