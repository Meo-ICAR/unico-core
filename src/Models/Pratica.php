<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Unico\Core\Concerns\UsesCoreConnection;

/** Registro delle pratiche di finanziamento/mutuo caricate. */
class Pratica extends Model
{
    use UsesCoreConnection;

    protected $table = 'pratiches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data_inserimento_pratica' => 'date',
            'rata' => 'decimal:2',
            'erogato' => 'decimal:2',
            'sended_at' => 'date',
            'approved_at' => 'date',
            'erogated_at' => 'date',
            'amount' => 'decimal:2',
            'net' => 'decimal:2',
            'is_notowned' => 'boolean',
            'upload_at' => 'datetime',
            'rejected_at' => 'date',
            'mediation_fee_percent' => 'decimal:2',
            'mediation_fee_amount' => 'decimal:2',
            'istruttoria_costs' => 'decimal:2',
            'offer_valid_until' => 'date',
        ];
    }

    public function clienti(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'clienti_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** Istruttore che segue la pratica. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function fornitori(): BelongsTo
    {
        return $this->belongsTo(Fornitore::class, 'fornitori_id');
    }

    public function praticaStato(): BelongsTo
    {
        return $this->belongsTo(PraticaStato::class, 'pratica_stato_id');
    }

    public function tipoprodotto(): BelongsTo
    {
        return $this->belongsTo(TipoProdotto::class, 'tipoprodotto_id');
    }

    public function immobili(): HasMany
    {
        return $this->hasMany(PraticaImmobile::class, 'pratica_id');
    }

    public function praticaClients(): HasMany
    {
        return $this->hasMany(PraticaClient::class, 'pratica_id');
    }

    public function praticaStatusHistory(): HasMany
    {
        return $this->hasMany(PraticaStatusHistory::class, 'pratica_id');
    }
}
