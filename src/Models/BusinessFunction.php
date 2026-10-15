<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Unico\Core\Concerns\UsesCoreConnection;

/** Funzione aziendale del funzionigramma, con i suoi membri (dipendenti o consulenti esterni). */
class BusinessFunction extends Model
{
    use UsesCoreConnection;

    protected $table = 'business_functions';

    protected $guarded = ['id'];

    public function members(): HasMany
    {
        return $this->hasMany(BusinessFunctionMember::class, 'business_function_id');
    }

    /** Dipendenti che ne fanno parte. */
    public function employees(): MorphToMany
    {
        return $this->morphedByMany(Employee::class, 'member', 'business_function_members')
            ->using(BusinessFunctionMember::class)
            ->withPivot('employee_type_id', 'is_manager')
            ->withTimestamps();
    }

    /** Consulenti esterni (clienti) a cui è esternalizzato un ruolo della funzione. */
    public function clients(): MorphToMany
    {
        return $this->morphedByMany(Client::class, 'member', 'business_function_members')
            ->using(BusinessFunctionMember::class)
            ->withPivot('employee_type_id', 'is_manager')
            ->withTimestamps();
    }
}
