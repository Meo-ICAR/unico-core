<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Registro dettaglio dei rimedi obbligatori per le anomalie rilevate. */
class Remediation extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'remediations';

    protected $guarded = ['id'];

    public function auditFindings(): HasMany
    {
        return $this->hasMany(AuditFinding::class, 'remediation_id');
    }
}
