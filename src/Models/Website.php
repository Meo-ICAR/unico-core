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

    /** Portali di un istituto: dove si caricano le pratiche, da dove si scaricano i moduli, collegamento via API. */
    public const PORTAL_CARICAMENTO = 'caricamento';

    public const PORTAL_MODULISTICA = 'modulistica';

    public const PORTAL_API = 'api';

    public const PORTALI = [
        self::PORTAL_CARICAMENTO => 'Caricamento pratiche',
        self::PORTAL_MODULISTICA => 'Modulistica',
        self::PORTAL_API => 'API',
    ];

    protected $table = 'websites';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Il dominio è obbligatorio: se si indica solo l'indirizzo, si ricava da lì.
        static::saving(function (Website $website): void {
            if (blank($website->domain) && filled($website->url)) {
                $website->domain = parse_url((string) $website->url, PHP_URL_HOST) ?: (string) $website->url;
            }

            if (blank($website->name) && filled($website->type)) {
                $website->name = self::PORTALI[$website->type] ?? (string) $website->type;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_typical' => 'boolean',
            'is_footercompilant' => 'boolean',
            'is_iso27001_certified' => 'boolean',
            'api_secret' => 'encrypted',
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
