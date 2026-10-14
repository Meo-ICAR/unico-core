<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function documentRequirements(): HasMany
    {
        return $this->hasMany(DocumentRequirement::class, 'task_id');
    }
}
