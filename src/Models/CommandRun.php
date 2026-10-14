<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Unico\Core\Concerns\UsesCoreConnection;

/** Lanci di comandi richiesti tramite il bridge API, con stato ed esito. */
class CommandRun extends Model
{
    use UsesCoreConnection;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $table = 'command_runs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['options' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED, self::SKIPPED], true);
    }
}
