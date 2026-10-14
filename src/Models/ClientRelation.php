<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesOwningAppConnection;

/** Legami tra anagrafiche clienti (familiari, soci, collegati). */
class ClientRelation extends Model
{
    use UsesOwningAppConnection;

    protected $table = 'client_relations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'shares_percentage' => 'decimal:2',
            'is_titolare' => 'boolean',
            'data_inizio_ruolo' => 'date',
            'data_fine_ruolo' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function clientType(): BelongsTo
    {
        return $this->belongsTo(ClientType::class, 'client_type_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
