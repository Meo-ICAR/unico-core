<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Indirizzi di qualsiasi anagrafica (morph), con tipo. */
class Address extends Model
{
    use UsesCoreConnection;

    protected $table = 'addresses';

    protected $guarded = ['id'];

    public function addressType(): BelongsTo
    {
        return $this->belongsTo(AddressType::class, 'address_type_id');
    }

    public function addressable(): MorphTo
    {
        return $this->morphTo();
    }
}
