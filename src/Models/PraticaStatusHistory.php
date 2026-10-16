<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Storico dei cambi di stato delle pratiche per la pipeline di avanzamento. */
class PraticaStatusHistory extends Model
{
    use UsesCoreConnection;

    protected $table = 'pratica_status_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
            'seen_at' => 'datetime',
        ];
    }

    public function pratica(): BelongsTo
    {
        return $this->belongsTo(Pratica::class, 'pratica_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Dipendente collegato all'utente che ha cambiato lo stato. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'user_id', 'user_id');
    }

    /** Chi ha preso visione del cambio di stato. */
    public function seenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seen_by');
    }

    /**
     * Cambi di stato di cui l'istruttore della pratica non ha preso visione e che non ha fatto lui: quelli del produttore
     * (origine `agenti`) o di un altro utente.
     */
    public function scopeDaVisionare(Builder $query): Builder
    {
        $istruttore = '(select employees.user_id from employees inner join pratiches on pratiches.employee_id = employees.id where pratiches.id = pratica_status_history.pratica_id)';

        return $query->whereNull('seen_at')->where(fn (Builder $q) => $q
            ->where('source', 'agenti')
            ->orWhere(fn (Builder $w) => $w->whereNotNull('user_id')->whereRaw("user_id <> {$istruttore}")));
    }
}
