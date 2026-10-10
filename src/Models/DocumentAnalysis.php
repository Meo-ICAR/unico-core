<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Storico delle analisi automatiche e manuali di un documento, con costi AI. */
class DocumentAnalysis extends Model
{
    use UsesCoreConnection;

    protected $table = 'document_analyses';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'result' => 'array',
            'ai_cost' => 'decimal:6',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
