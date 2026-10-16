<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Unico\Core\Concerns\UsesCoreConnection;

/** Plico documentale: definizione dei documenti richiesti (tabella `tasks`). Le attività di processo BPM stanno su `process_task`. */
class Task extends Model
{
    use UsesCoreConnection;

    protected $table = 'tasks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** I task validi per una company: quelli senza company e quelli riservati a lei. */
    public function scopeForCompany(Builder $query, ?int $companyId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('company_id')->when($companyId, fn (Builder $q) => $q->orWhere('company_id', $companyId)));
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function documentRequirements(): HasMany
    {
        return $this->hasMany(DocumentRequirement::class, 'task_id');
    }

    /**
     * Classe del model a cui si aggancia il plico (`taskable` è l'alias del morph map).
     *
     * @return class-string<Model>|null
     */
    public function taskableClass(): ?string
    {
        return $this->taskable ? Relation::getMorphedModel($this->taskable) : null;
    }

    /**
     * Verifica se il plico è applicabile al record: dev'essere del model collegato (`taskable`), passare le due condizioni
     * di attivazione (`trigger_field`/`trigger_state`/`trigger_value` e `trigger_subfield` = `trigger_subvalue`) e non
     * rientrare nella condizione di esclusione (`exclude_*`).
     */
    public function matchesConditions(Model $record): bool
    {
        $class = $this->taskableClass();

        if ($class !== null && ! $record instanceof $class) {
            return false;
        }

        if (! empty($this->exclude_field) && ! empty($this->exclude_state) && $this->fieldMatches($record, $this->exclude_field, $this->exclude_state, $this->exclude_value)) {
            return false;
        }

        if (! empty($this->trigger_field) && ! empty($this->trigger_state) && ! $this->fieldMatches($record, $this->trigger_field, $this->trigger_state, $this->trigger_value)) {
            return false;
        }

        return empty($this->trigger_subfield) || $this->fieldMatches($record, $this->trigger_subfield, 'equals', $this->trigger_subvalue);
    }

    /**
     * I plichi radice attivi applicabili al record (i figli si attivano al completamento del padre), validi per la sua company.
     *
     * @return Collection<int, static>
     */
    public static function applicabiliA(Model $record, ?int $companyId = null): Collection
    {
        return static::query()
            ->where('taskable', Relation::getMorphAlias($record::class))
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->forCompany($companyId)
            ->get()
            ->filter(fn (self $task): bool => $task->matchesConditions($record))
            ->values();
    }

    /** Applica una condizione (`filled`, `empty`, `equals`) al campo del record. */
    protected function fieldMatches(Model $record, string $field, ?string $state, ?string $value): bool
    {
        $fieldValue = $record->{$field};

        if ($fieldValue instanceof \BackedEnum) {
            $fieldValue = $fieldValue->value;
        }

        return match ($state) {
            'empty' => empty($fieldValue),
            'equals' => (string) $fieldValue === (string) $value,
            default => ! empty($fieldValue),
        };
    }

    public function tipoprodotto(): BelongsTo
    {
        return $this->belongsTo(TipoProdotto::class, 'tipoprodotto_id');
    }

    /** I task di pratica legati a un prodotto: per riferimento, e per nome (`trigger_value`) dove il riferimento non è ancora valorizzato. */
    public function scopeDelProdotto(Builder $query, string $productName): Builder
    {
        $productId = TipoProdotto::query()->where('name', $productName)->value('id');

        return $query->where('trigger_field', 'tipo_prodotto')->where(function (Builder $q) use ($productId, $productName): void {
            $q->when($productId !== null, fn (Builder $w) => $w->where('tipoprodotto_id', $productId))
                ->orWhere(fn (Builder $w) => $w->whereNull('tipoprodotto_id')->where('trigger_value', $productName));
        });
    }

    /**
     * Requisiti del task con quelli ereditati dal task padre (`parent_id`): il figlio aggiunge o sostituisce i documenti
     * (stesso tipo) del padre. Così il mutuo per ristrutturazione eredita la check list del mutuo senza copiarla.
     *
     * @return Collection<int, DocumentRequirement>
     */
    public function requisitiEffettivi(): Collection
    {
        $propri = $this->documentRequirements()->with('documentType')->orderBy('sort_order')->get();
        $ereditati = $this->parent?->requisitiEffettivi() ?? collect();

        $ids = $propri->pluck('document_type_id');

        return $ereditati->reject(fn (DocumentRequirement $r): bool => $ids->contains($r->document_type_id))->concat($propri)->values();
    }
}
