<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesOwningAppConnection;

/** Dati aggregati per il report OAM: Profilo Economico/Operativo Base. */
class OamSemestrale extends Model
{
    use UsesOwningAppConnection;

    protected $table = 'oam_semestrales';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'erogato_lordo' => 'decimal:2',
            'erogato_lavorazione' => 'decimal:2',
            'provv_clientela' => 'decimal:2',
            'provv_istituto_comp' => 'decimal:2',
            'premi_istituto_comp' => 'decimal:2',
            'payin_ass_banche' => 'decimal:2',
            'payin_ass_broker' => 'decimal:2',
            'payin_ass_broker_cap' => 'decimal:2',
            'payout_rete_credito' => 'decimal:2',
            'payout_rete_ass_banche' => 'decimal:2',
            'payout_rete_ass_broker' => 'decimal:2',
            'payout_rete_ass_broker_cap' => 'decimal:2',
            'importo_retrocesse' => 'decimal:2',
            'is_convenzione' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
