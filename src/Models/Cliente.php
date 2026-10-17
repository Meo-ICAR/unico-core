<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Anagrafica Banche ed Istituti Finanziari (Clienti del mediatore). */
class Cliente extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'clientis';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stipulated_at' => 'date',
            'dismissed_at' => 'date',
            'oam_at' => 'date',
            'ivass_at' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_exclusive' => 'boolean',
            'is_reported' => 'boolean',
            'is_dummy' => 'boolean',
        ];
    }

    /** Portali dell'istituto (caricamento, modulistica, API) e altri siti. */
    public function websites(): MorphMany
    {
        return $this->morphMany(Website::class, 'websiteable');
    }

    /** Indirizzo del portale di quel tipo, se c'è ed è attivo. */
    public function portalUrl(string $kind): ?string
    {
        $url = $this->websites()->where('type', $kind)->where('is_active', true)->value('url');

        return blank($url) ? null : (str_starts_with((string) $url, 'http') ? (string) $url : 'https://'.$url);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function customertype(): BelongsTo
    {
        return $this->belongsTo(ClientType::class, 'customertype_id');
    }

    public function blacklistClientiEmployees(): HasMany
    {
        return $this->hasMany(BlacklistClienteEmployee::class, 'cliente_id');
    }

    public function blacklistClientiFornitori(): HasMany
    {
        return $this->hasMany(BlacklistClienteFornitore::class, 'cliente_id');
    }

    public function clientiOam(): HasMany
    {
        return $this->hasMany(ClienteOam::class, 'clienti_id');
    }

    public function complaintRegistry(): HasMany
    {
        return $this->hasMany(ComplaintRegistry::class, 'bank_id');
    }

    /** Documenti specifici di questo istituto (document_types.clienti_id). */
    public function documentTypes(): HasMany
    {
        return $this->hasMany(DocumentType::class, 'clienti_id');
    }

    public function pratiches(): HasMany
    {
        return $this->hasMany(Pratica::class, 'clienti_id');
    }

    public function provvigioniRules(): HasMany
    {
        return $this->hasMany(ProvvigioniRule::class, 'clienti_id');
    }

    public function tipoprodottoSubConstraints(): HasMany
    {
        return $this->hasMany(TipoProdottoSubConstraint::class, 'clienti_id');
    }
}
