<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesOwningAppConnection;

/** Sessioni di controllo qualità: campione casuale di pratiche da sottoporre al responsabile qualità. */
class QualityReview extends Model
{
    use SoftDeletes, UsesOwningAppConnection;

    protected $table = 'quality_reviews';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'filters' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(Audit::class, 'quality_review_id');
    }
}
