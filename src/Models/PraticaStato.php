<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Stati del workflow di una pratica di finanziamento. */
class PraticaStato extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'pratica_stati';

    protected $guarded = ['id'];

    /** Il nome dello stato è sempre in maiuscolo. */
    protected function name(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => $value === null ? null : mb_strtoupper($value));
    }

    /** Il codice dello stato è sempre in maiuscolo. */
    protected function codice(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => $value === null ? null : mb_strtoupper($value));
    }

    protected function casts(): array
    {
        return [
            'is_rejected' => 'boolean',
            'is_suspended' => 'boolean',
            'is_sended' => 'boolean',
            'is_approved' => 'boolean',
            'is_erogated' => 'boolean',
            'is_working' => 'boolean',
            'is_estingued' => 'boolean',
        ];
    }

    public function pratiches(): HasMany
    {
        return $this->hasMany(Pratica::class, 'pratica_stato_id');
    }
}
