<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Unico\Core\Concerns\UsesCoreConnection;

/** Clienti finali: persone fisiche e giuridiche, con dati KYC e consensi privacy. */
class Client extends Model
{
    use UsesCoreConnection;

    protected $table = 'clients';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_person' => 'boolean',
            'is_pep' => 'boolean',
            'is_sanctioned' => 'boolean',
            'is_remote_interaction' => 'boolean',
            'general_consent_at' => 'datetime',
            'privacy_policy_read_at' => 'datetime',
            'consent_special_categories_at' => 'datetime',
            'consent_sic_at' => 'datetime',
            'consent_marketing_at' => 'datetime',
            'consent_profiling_at' => 'datetime',
            'is_company' => 'boolean',
            'is_lead' => 'boolean',
            'acquired_at' => 'datetime',
            'privacy_consent' => 'boolean',
            'is_client' => 'boolean',
            'is_requiredApprovation' => 'boolean',
            'is_approved' => 'boolean',
            'is_anonymous' => 'boolean',
            'blacklist_at' => 'datetime',
            'salary' => 'decimal:2',
            'salary_quote' => 'decimal:2',
            'is_art108' => 'boolean',
            'is_consultant_gdpr' => 'boolean',
            'is_iso27001_certified' => 'boolean',
            'is_dummy' => 'boolean',
            'employee_roles' => 'array',
            'birth_date' => 'date',
            'hiring_date' => 'date',
            'company_started_at' => 'date',
            'share_capital' => 'decimal:2',
            'turnover' => 'decimal:2',
            'net_worth' => 'decimal:2',
            'registry_updated_at' => 'datetime',
        ];
    }

    public function blacklistedByRecord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blacklisted_by');
    }

    public function clientType(): BelongsTo
    {
        return $this->belongsTo(ClientType::class, 'client_type_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function employer(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'employer_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Client::class, 'employer_id');
    }

    public function leadsource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'leadsource_id');
    }

    public function legalRepresentative(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'legal_representative_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Sedi del cliente (indirizzo di residenza o sede legale). */
    public function branches(): MorphMany
    {
        return $this->morphMany(Branch::class, 'branchable');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function clientMandates(): HasMany
    {
        return $this->hasMany(ClientMandate::class, 'client_id');
    }

    public function clientRelations(): HasMany
    {
        return $this->hasMany(ClientRelation::class, 'client_id');
    }

    public function praticaClients(): HasMany
    {
        return $this->hasMany(PraticaClient::class, 'client_id');
    }

    public function suspiciousActivityReports(): HasMany
    {
        return $this->hasMany(SuspiciousActivityReport::class, 'client_id');
    }
}
