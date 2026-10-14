<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Unico\Core\Concerns\UsesCoreConnection;

/** Anagrafica dei tipi/ruoli privacy di cliente. */
class ClientType extends Model
{
    use UsesCoreConnection;

    protected $table = 'client_types';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_person' => 'boolean',
            'is_company' => 'boolean',
        ];
    }

    public function clientis(): HasMany
    {
        return $this->hasMany(Cliente::class, 'customertype_id');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'client_type_id');
    }

    public function clientRelations(): HasMany
    {
        return $this->hasMany(ClientRelation::class, 'client_type_id');
    }
}
