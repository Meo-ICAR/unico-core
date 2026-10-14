<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Divieti di contatto tra un cliente e un dipendente. */
class BlacklistClienteEmployee extends Model
{
    use UsesCoreConnection;

    protected $table = 'blacklist_clienti_employees';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data_inizio' => 'date',
            'data_fine' => 'date',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
