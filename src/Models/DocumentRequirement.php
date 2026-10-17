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
            'request_to_issuer' => 'boolean',
            'client_roles' => 'array',
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
     * pratica — i client della pratica con uno dei ruoli in `client_roles` (nullo = tutti) che sono del tipo del documento:
     * persona fisica o giuridica secondo `document_types.client_scope`.
     *
     * @return array<int, array{0: string, 1: string}> coppie alias/id del proprietario
     */
    public function ownersFor(Model $record, string $recordAlias): array
    {
        if ($this->documentable === 'client' && $record instanceof Pratica) {
            $type = $this->documentType;

            return $record->praticaClients()
                ->when(filled($this->client_roles), fn ($query) => $query->whereIn('role', $this->client_roles))
                ->with('client')
                ->get()
                ->filter(fn (PraticaClient $link): bool => $link->client !== null && ($type === null || $type->appliesToPerson((bool) $link->client->is_person)))
                ->pluck('client_id')
                ->unique()
                ->map(fn ($clientId): array => ['client', (string) $clientId])
                ->values()
                ->all();
        }

        return [[$recordAlias, (string) $record->getKey()]];
    }

    /** Chi firma il documento in questo task: quello indicato nel task, altrimenti quello del tipo documento. */
    public function signerModel(): ?string
    {
        return $this->signer_model ?: $this->documentType?->signer_model;
    }
}
