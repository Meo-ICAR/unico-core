<?php

namespace Unico\Core\Models;

use Unico\Core\Enums\IbanHolder;
use Unico\Core\Enums\MaritalStatus;
use Unico\Core\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Unico\Core\Concerns\UsesCoreConnection;
use Unico\Core\Enums\DocumentStatus;

/** Clienti finali: persone fisiche e giuridiche, con dati KYC e consensi privacy. */
class Client extends Model
{
    use UsesCoreConnection;

    protected $table = 'clients';

    protected $guarded = ['id'];

    /** Tipi dei due documenti d'identità che ogni persona fisica ha da raccogliere (codice o nome del tipo). */
    public const DOCUMENTI_INIZIALI = [['code', 'CARTA_IDENTITA'], ['name', 'Patente di Guida']];

    /** Ogni nuova persona fisica nasce con i due indirizzi (residenza, domicilio) e i due documenti d'identità da compilare. */
    protected static function booted(): void
    {
        static::created(fn (self $client) => $client->preparaAnagrafica());
    }

    /**
     * Crea, se mancano, residenza e domicilio e i due documenti d'identità (richiesti, da caricare). Non tocca quello che c'è già.
     *
     * @return array{indirizzi: int, documenti: int} quanti ne ha creati
     */
    public function preparaAnagrafica(): array
    {
        $creati = ['indirizzi' => 0, 'documenti' => 0];

        if (! $this->is_person) {
            return $creati;
        }

        foreach (AddressType::query()->whereIn('name', ['Residenza', 'Domicilio'])->orderBy('id')->get() as $tipo) {
            $indirizzo = $this->addresses()->firstOrCreate(['address_type_id' => $tipo->getKey()]);
            $creati['indirizzi'] += $indirizzo->wasRecentlyCreated ? 1 : 0;
        }

        foreach (self::DOCUMENTI_INIZIALI as [$campo, $valore]) {
            $tipo = DocumentType::query()->where($campo, $valore)->first();

            if ($tipo === null) {
                continue;
            }

            $documento = Document::query()->firstOrCreate(
                ['documentable_type' => 'client', 'documentable_id' => (string) $this->getKey(), 'document_type_id' => $tipo->getKey()],
                ['name' => $tipo->name, 'company_id' => is_numeric($this->company_id) ? (int) $this->company_id : null, 'status' => DocumentStatus::PENDING->value],
            );
            $creati['documenti'] += $documento->wasRecentlyCreated ? 1 : 0;
        }

        return $creati;
    }

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
            'consent_group_at' => 'datetime',
            'consent_third_parties_at' => 'datetime',
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
            'marital_status' => MaritalStatus::class,
            'payment_method' => PaymentMethod::class,
            'iban_holder' => IbanHolder::class,
            'family_members' => 'integer',
            'family_members_with_income' => 'integer',
            'salary_months' => 'integer',
            'salary_day' => 'integer',
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

    public function occupationType(): BelongsTo
    {
        return $this->belongsTo(OccupationType::class, 'occupation_type_id');
    }

    public function hiringType(): BelongsTo
    {
        return $this->belongsTo(HiringType::class, 'hiring_type_id');
    }

    public function incomeType(): BelongsTo
    {
        return $this->belongsTo(IncomeType::class, 'income_type_id');
    }

    /** La banca preferita del cliente (clientis). */
    public function preferredBank(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'preferred_bank_id');
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

    /** Indirizzi della persona fisica (residenza, domicilio): al posto delle sedi, che restano per le società. */
    public function addresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable');
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
