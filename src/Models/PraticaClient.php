<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesCoreConnection;
use Unico\Core\Enums\Kinship;

/** Richiedente, coobbligati e garanti di una pratica. */
class PraticaClient extends Model
{
    use UsesCoreConnection;

    protected $table = 'pratica_clients';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['kinship' => Kinship::class];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function pratica(): BelongsTo
    {
        return $this->belongsTo(Pratica::class, 'pratica_id');
    }
}
