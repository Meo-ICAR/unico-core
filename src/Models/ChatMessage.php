<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Messaggi delle conversazioni con l'assistente AI (NeuronAI). */
class ChatMessage extends Model
{
    use UsesCoreConnection;

    protected $table = 'chat_messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'meta' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
