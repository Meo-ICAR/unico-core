<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Sito web o landing page di un'azienda, fornitore, cliente o banca, con le date degli adempimenti legali. */
class Website extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'websites';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_typical' => 'boolean',
            'is_footercompilant' => 'boolean',
            'is_iso27001_certified' => 'boolean',
            'privacy_date' => 'date',
            'transparency_date' => 'date',
            'privacy_prior_date' => 'date',
            'transparency_prior_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** Proprietario del sito: company, fornitore, client o cliente (alias della morph map). */
    public function websiteable(): MorphTo
    {
        return $this->morphTo();
    }
}
