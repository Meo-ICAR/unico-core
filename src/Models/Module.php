<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Unico\Core\Concerns\UsesCoreConnection;

/** Elenco dei moduli/prodotti disponibili nella suite. */
class Module extends Model
{
    use UsesCoreConnection;

    protected $table = 'modules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function companyModules(): HasMany
    {
        return $this->hasMany(CompanyModule::class, 'module_id');
    }
}
