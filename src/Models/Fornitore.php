<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Anagrafica Agenti di commercio / Collaboratori (Fornitori di servizi). */
class Fornitore extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'fornitoris';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stipulated_at' => 'date',
            'oam_at' => 'date',
            'ivass_at' => 'date',
            'dismissed_at' => 'date',
            'is_active' => 'boolean',
            'is_art108' => 'boolean',
            'allows_direct_requests' => 'boolean',
            'oam_dismissed_at' => 'date',
            'welcome_bonus' => 'decimal:2',
            'available_at' => 'date',
            'budget' => 'decimal:2',
            'natoil' => 'date',
            'anticipo' => 'decimal:2',
            'anticipo_residuo' => 'decimal:2',
            'contributo' => 'decimal:2',
            'iscollaboratore' => 'boolean',
            'isdipendente' => 'boolean',
            'contributodalmese' => 'date',
            'employee_roles' => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function fornitorirole(): BelongsTo
    {
        return $this->belongsTo(FornitoriRole::class, 'fornitorirole_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function blacklistClientiFornitori(): HasMany
    {
        return $this->hasMany(BlacklistClienteFornitore::class, 'fornitore_id');
    }

    public function complaintRegistry(): HasMany
    {
        return $this->hasMany(ComplaintRegistry::class, 'agent_id');
    }

    public function pratiches(): HasMany
    {
        return $this->hasMany(Pratica::class, 'fornitori_id');
    }

    public function provvigioniRules(): HasMany
    {
        return $this->hasMany(ProvvigioniRule::class, 'fornitori_id');
    }
}
