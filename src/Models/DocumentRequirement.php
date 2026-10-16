<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Quali tipi documento servono per un prodotto/sottoprodotto o per un task (NULL = vale per tutti). */
class DocumentRequirement extends Model
{
    use UsesCoreConnection;

    protected $table = 'document_requirements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function tipoprodotto(): BelongsTo
    {
        return $this->belongsTo(TipoProdotto::class, 'tipoprodotto_id');
    }

    public function tipoprodottoSub(): BelongsTo
    {
        return $this->belongsTo(TipoProdottoSub::class, 'tipoprodotto_sub_id');
    }

    /**
     * I model su cui nasce il documento richiesto per il record: il record stesso, oppure — per `documentable` = client su una
     * pratica — i client della pratica, tutti o solo quelli del ruolo indicato in `client_role`.
     *
     * @return array<int, array{0: string, 1: string}> coppie alias/id del proprietario
     */
    public function ownersFor(Model $record, string $recordAlias): array
    {
        if ($this->documentable === 'client' && $record instanceof Pratica) {
            return $record->praticaClients()
                ->when($this->client_role !== null, fn ($query) => $query->where('role', $this->client_role))
                ->pluck('client_id')
                ->unique()
                ->map(fn ($clientId): array => ['client', (string) $clientId])
                ->values()
                ->all();
        }

        return [[$recordAlias, (string) $record->getKey()]];
    }
}
