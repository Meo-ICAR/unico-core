<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Unico\Core\Concerns\UsesOwningAppConnection;

/** Verifiche dei requisiti di onorabilità delle persone che operano per l'azienda. */
class Onorabilita extends Model
{
    use UsesOwningAppConnection;

    protected $table = 'onorabilita';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'data_ultimo_carico_pendente' => 'date',
            'data_ultima_onorabilita' => 'date',
        ];
    }
}
