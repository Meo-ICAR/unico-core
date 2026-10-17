<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Builder;
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
            'presa_in_carico_at' => 'date',
            'lavorazione_at' => 'date',
            'sended_at' => 'date',
            'approved_at' => 'date',
            'erogated_at' => 'date',
            'amount' => 'decimal:2',
            'net' => 'decimal:2',
            'is_notowned' => 'boolean',
            'allows_direct_requests' => 'boolean',
            'upload_at' => 'datetime',
            'scadenza' => 'date',
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

    /**
     * Compenso di mediazione richiesto al cliente: l'importo fisso, altrimenti la percentuale sull'importo richiesto.
     */
    public static function mediationFee(float|int|string|null $fixed, float|int|string|null $percent, float|int|string|null $amount): ?float
    {
        if (filled($fixed)) {
            return round((float) $fixed, 2);
        }

        return filled($percent) && filled($amount) ? round((float) $amount * (float) $percent / 100, 2) : null;
    }

    public function mediationFeeDue(): ?float
    {
        return static::mediationFee($this->mediation_fee_amount, $this->mediation_fee_percent, $this->amount);
    }

    public function immobili(): HasMany
    {
        return $this->hasMany(PraticaImmobile::class, 'pratica_id');
    }

    public function praticaClients(): HasMany
    {
        return $this->hasMany(PraticaClient::class, 'pratica_id');
    }

    /** Pratiche con cambi di stato non fatti dall'istruttore e di cui non ha ancora preso visione. */
    public function scopeConStatiDaVisionare(Builder $query): Builder
    {
        return $query->whereExists(PraticaStatusHistory::query()->daVisionare()->whereColumn('pratica_status_history.pratica_id', 'pratiches.id'));
    }

    public function praticaStatusHistory(): HasMany
    {
        return $this->hasMany(PraticaStatusHistory::class, 'pratica_id');
    }
}
