<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Registro delle attività: chi ha fatto cosa su quale record, con rischio e impronta di integrità. */
class ActivityLog extends Model
{
    use UsesCoreConnection;

    protected $table = 'activity_log';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attribute_changes' => 'array',
            'properties' => 'array',
            'retention_hold' => 'boolean',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function causer(): MorphTo
    {
        return $this->morphTo();
    }
}
