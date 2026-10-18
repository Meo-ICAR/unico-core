<?php

namespace Unico\Core\Access;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Unico\Core\Models\Company;
use Unico\Core\Models\CompanyModule;
use Unico\Core\Models\EmployeeType;
use Unico\Core\Models\EmployeeTypePermission;
use Unico\Core\Models\EmployeeTypeResourcePreset;
use Unico\Core\Models\Resource;

/**
 * Logica di accesso e autorizzazione della suite Unico.
 *
 * Ruoli su `users.role`:
 *  1. `superadmin` (o `super_admin`): ha accesso su tutto (qualsiasi company, qualsiasi risorsa, lettura e scrittura).
 *  2. `admin`: accesso completo su tutti i model con company della propria azienda (lettura e scrittura per tutto ciò
 *     che il piano copre, senza passare dai ruoli EmployeeType).
 *  3. `inspector`: come admin ma solo in lettura ($write = true risponde sempre false).
 *  4. Ruoli di riferimento (`employee`, `client`, `fornitore`): indicano il model Eloquent collegato tramite `user_id`
 *     che punta alla tabella `users`. Da questo modello si deducono la company e i ruoli `employee_roles` per i permessi
 *     EmployeeType sulle singole risorse; l'accesso ai modelli è limitato al proprio record e a quelli a esso collegati
 *     (o permessi da EmployeeType).
 */
class AccessChecker
{
    public const REFERENCE_MODELS = [
        'employee' => \Unico\Core\Models\Employee::class,
        'client' => \Unico\Core\Models\Client::class,
        'fornitore' => \Unico\Core\Models\Fornitore::class,
    ];

    /** @var array<string, bool> */
    private array $checks = [];

    /** @var array<string, PlanType> */
    private array $plans = [];

    /** @var array<string, list<int>> */
    private array $roles = [];

    /** @var array<string, ?object> */
    private array $referenceModels = [];

    public function appName(): string
    {
        return (string) config('app.name');
    }

    /** Codice del modulo in `modules.code` (es. «Unico BPM» → UNICOBPM). */
    public function moduleCode(): string
    {
        return strtoupper(str_replace(' ', '', $this->appName()));
    }

    /**
     * `$write`: la funzione modifica i dati (creare, modificare, eliminare, azioni): i ruoli di sola lettura non la ottengono mai.
     * `$action` (create, update, delete) chiede al ruolo proprio quel permesso della matrice; senza, basta uno qualunque dei permessi di scrittura.
     */
    public function check(string $feature, ?string $callerClass = null, ?object $user = null, bool $write = false, ?string $action = null): bool
    {
        $user ??= $this->currentUser();
        $key = $feature.'|'.($user?->getKey() ?? 'guest').'|'.$this->plan($user)->value.'|'.(int) $write.'|'.$action;

        return $this->checks[$key] ??= $this->resolve($feature, $user, $write, $action);
    }

    /** L'utente è superadmin: ha accesso su tutto. */
    public function isSuperAdmin(?object $user = null): bool
    {
        $role = $this->roleOf($user ?? $this->currentUser());

        if ($role === null) {
            return false;
        }

        $superAdminRoles = (array) config('unico-core.access.superadmin_roles', ['superadmin', 'super_admin']);
        $bypassRoles = (array) config('unico-core.access.bypass_roles', ['superadmin', 'super_admin']);

        return in_array($role, $superAdminRoles, true) || in_array($role, $bypassRoles, true);
    }

    /** L'utente è admin: accesso su tutti i model con company della propria azienda. */
    public function isAdmin(?object $user = null): bool
    {
        $role = $this->roleOf($user ?? $this->currentUser());

        if ($role === null) {
            return false;
        }

        $adminRoles = (array) config('unico-core.access.admin_roles', ['admin']);

        return in_array($role, $adminRoles, true);
    }

    /** L'utente è inspector: come admin, ma solo in lettura. */
    public function isInspector(?object $user = null): bool
    {
        $role = $this->roleOf($user ?? $this->currentUser());

        if ($role === null) {
            return false;
        }

        $inspectorRoles = (array) config('unico-core.access.readonly_roles', ['inspector']);

        return in_array($role, $inspectorRoles, true);
    }

    /** L'utente ha un ruolo di sola lettura (l'ispettore): vede, non modifica. */
    public function isReadOnly(?object $user = null): bool
    {
        return $this->isInspector($user);
    }

    /** L'utente ha un ruolo che indica un modello di riferimento (employee, client, fornitore). */
    public function isReferenceRole(?object $user = null): bool
    {
        $role = $this->roleOf($user ?? $this->currentUser());

        return $role !== null && $this->referenceModelClass($role) !== null;
    }

    /** Restituisce la classe del modello di riferimento per il ruolo specificato. */
    public function referenceModelClass(string $role): ?string
    {
        $role = strtolower(trim($role));
        $map = (array) config('unico-core.access.reference_roles', self::REFERENCE_MODELS);

        return $map[$role] ?? null;
    }

    /** Restituisce l'istanza del model di riferimento collegata all'utente tramite user_id. */
    public function referenceModel(?object $user = null): ?object
    {
        $user ??= $this->currentUser();

        if ($user === null) {
            return null;
        }

        $userKey = (string) ($user->getKey() ?? spl_object_hash($user));

        if (array_key_exists($userKey, $this->referenceModels)) {
            return $this->referenceModels[$userKey];
        }

        $role = $this->roleOf($user);

        if ($role === null) {
            return $this->referenceModels[$userKey] = null;
        }

        $modelClass = $this->referenceModelClass($role);

        if ($modelClass === null) {
            return $this->referenceModels[$userKey] = null;
        }

        // Se l'utente ha già una relazione caricata o una proprietà con il model di riferimento
        if (isset($user->{$role}) && $user->{$role} instanceof $modelClass) {
            return $this->referenceModels[$userKey] = $user->{$role};
        }

        if (isset($user->profile) && $user->profile instanceof $modelClass) {
            return $this->referenceModels[$userKey] = $user->profile;
        }

        $userId = $user->getKey() ?? $user->id ?? null;

        if ($userId === null || ! class_exists($modelClass)) {
            return $this->referenceModels[$userKey] = null;
        }

        return $this->referenceModels[$userKey] = $modelClass::query()
            ->where('user_id', $userId)
            ->first();
    }

    /** Piano effettivo dell'utente (della sua company) per l'app corrente. */
    public function plan(?object $user = null): PlanType
    {
        $user ??= $this->currentUser();
        $default = PlanType::parse(config('unico-core.access.default_plan'), PlanType::Full);
        $forced = config('unico-core.access.plan');

        if (filled($forced)) {
            return PlanType::parse($forced, $default);
        }

        return $this->plans[(string) ($user?->getKey() ?? 'guest')] ??= $this->planFromCompany($user, $default);
    }

    /** Il modulo acquistato dalla company dell'utente per questa app, se c'è. */
    public function companyModule(?object $user = null): ?CompanyModule
    {
        $companyId = $this->companyIdOf($user ?? $this->currentUser());

        if ($companyId === null) {
            return null;
        }

        return CompanyModule::query()
            ->where('company_id', $companyId)
            ->whereHas('module', fn ($query) => $query->where('code', $this->moduleCode()))
            ->first();
    }

    /** Fine del periodo di prova, se la company è in prova su questa app. */
    public function trialEndsAt(?object $user = null): ?\Illuminate\Support\Carbon
    {
        $module = $this->companyModule($user);

        return PlanType::parse($module?->plan_type, PlanType::Full) === PlanType::Trial ? $module->trial_ends_at : null;
    }

    /** @return list<int> id degli EmployeeType dei ruoli dell'utente (vuoto senza profilo o senza ruoli) */
    public function employeeTypeIds(?object $user): array
    {
        $ref = $this->referenceModel($user);
        $rawRoles = $ref?->employee_roles ?? $user?->profile?->employee_roles ?? null;

        $roles = Arr::wrap($this->decode($rawRoles));

        if ($roles === []) {
            return [];
        }

        return $this->roles[implode('|', $roles)] ??= EmployeeType::query()->whereIn('name', $roles)->pluck('id')->all();
    }

    /** Verifica se l'utente può accedere a un modello/record (e se ha permessi di scrittura). */
    public function canAccessModel(mixed $model, ?object $user = null, bool $write = false): bool
    {
        $user ??= $this->currentUser();

        if ($user === null) {
            return false;
        }

        // 1. Superadmin ha accesso su tutto
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // 3. Inspector: solo in lettura
        if ($write && $this->isReadOnly($user)) {
            return false;
        }

        $userCompanyId = $this->companyIdOf($user);

        if (is_string($model)) {
            if (class_exists($model)) {
                $instance = new $model;
                if ($this->isAdmin($user) || $this->isInspector($user)) {
                    return $userCompanyId !== null && $this->modelHasCompany($instance);
                }

                return $this->check($instance->getTable(), null, $user, $write);
            }

            return $this->check($model, null, $user, $write);
        }

        if (! is_object($model)) {
            return false;
        }

        $modelCompanyId = $this->companyIdOfModel($model);

        // Se il modello ha una company, deve appartenere alla stessa company dell'utente
        if ($modelCompanyId !== null) {
            if ($userCompanyId === null || (string) $modelCompanyId !== (string) $userCompanyId) {
                return false;
            }

            // 2. Admin ha accesso su tutti i model con company
            if ($this->isAdmin($user)) {
                return true;
            }

            // 3. Inspector come admin ma solo in lettura
            if ($this->isInspector($user)) {
                return ! $write;
            }

            // 4. Ruoli di riferimento (employee, client, fornitore)
            return $this->canReferenceAccessModel($model, $user, $write);
        }

        return false;
    }

    /** Applica lo scope dell'utente a una query Eloquent in base al suo ruolo e alla sua company. */
    public function scopeModel(Builder $query, ?object $user = null, bool $write = false): Builder
    {
        $user ??= $this->currentUser();

        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isSuperAdmin($user)) {
            return $query;
        }

        if ($this->isReadOnly($user) && $write) {
            return $query->whereRaw('1 = 0');
        }

        $userCompanyId = $this->companyIdOf($user);

        if ($userCompanyId === null) {
            return $query->whereRaw('1 = 0');
        }

        $model = $query->getModel();
        $table = $model->getTable();

        // Admin e inspector vedono tutti i record della company
        if ($this->isAdmin($user) || $this->isInspector($user)) {
            if ($table === 'companies') {
                return $query->where($model->getQualifiedKeyName(), $userCompanyId);
            }

            return $query->where($table.'.company_id', $userCompanyId);
        }

        // Ruolo di riferimento
        $role = $this->roleOf($user);
        $ref = $this->referenceModel($user);

        if ($ref === null) {
            return $query->whereRaw('1 = 0');
        }

        $refId = method_exists($ref, 'getKey') ? $ref->getKey() : ($ref->id ?? null);
        $userId = method_exists($user, 'getKey') ? $user->getKey() : ($user->id ?? null);

        // Scope company
        if ($table === 'companies') {
            $query->where($model->getQualifiedKeyName(), $userCompanyId);
        } else {
            $query->where($table.'.company_id', $userCompanyId);
        }

        // Se la query è sulla tabella del modello di riferimento
        $refClass = $this->referenceModelClass($role ?? '');
        if ($refClass !== null && $model instanceof $refClass) {
            return $query->where($model->getQualifiedKeyName(), $refId);
        }

        if ($role === 'employee') {
            $query->where(function (Builder $q) use ($table, $refId, $userId) {
                $q->where($table.'.employee_id', $refId)
                  ->orWhere($table.'.user_id', $userId);
            });
        } elseif ($role === 'client') {
            $query->where(function (Builder $q) use ($table, $refId, $userId) {
                $q->where($table.'.client_id', $refId)
                  ->orWhere($table.'.user_id', $userId);
            });
        } elseif ($role === 'fornitore') {
            $query->where(function (Builder $q) use ($table, $refId, $userId) {
                $q->where($table.'.fornitori_id', $refId)
                  ->orWhere($table.'.fornitore_id', $refId)
                  ->orWhere($table.'.user_id', $userId);
            });
        }

        return $query;
    }

    /** Ricava il company_id di un modello (o di una Company). */
    public function companyIdOfModel(object $model): int|string|null
    {
        if ($model instanceof Company) {
            return $model->getKey();
        }

        if (isset($model->company_id) && filled($model->company_id)) {
            return $model->company_id;
        }

        if (method_exists($model, 'getAttribute') && filled($model->getAttribute('company_id'))) {
            return $model->getAttribute('company_id');
        }

        return null;
    }

    /** Verifica se un modello appartiene all'ambito company. */
    public function modelHasCompany(object $model): bool
    {
        if ($model instanceof Company) {
            return true;
        }

        if (isset($model->company_id)) {
            return true;
        }

        if (method_exists($model, 'getTable')) {
            $table = $model->getTable();

            return in_array($table, [
                'companies', 'employees', 'clients', 'clientis', 'fornitoris',
                'branches', 'pratiches', 'documents', 'document_types',
                'document_requests', 'mail_accounts', 'incoming_emails',
                'audits', 'websites', 'training_records', 'complaints',
                'complaint_registry', 'quality_reviews', 'company_modules',
                'company_roles', 'oam_pratiches', 'oam_semestrali',
            ], true);
        }

        return false;
    }

    /** Dimentica i risultati memorizzati (test, cambi di licenza a metà richiesta). */
    public function flush(): void
    {
        $this->checks = $this->plans = $this->roles = $this->referenceModels = [];
    }

    public function companyIdOf(?object $user): int|string|null
    {
        if ($user === null) {
            return null;
        }

        if (isset($user->company_id) && filled($user->company_id)) {
            return $user->company_id;
        }

        $ref = $this->referenceModel($user);

        if ($ref !== null && isset($ref->company_id) && filled($ref->company_id)) {
            return $ref->company_id;
        }

        return $user->profile?->company_id ?? null;
    }

    public function roleOf(?object $user): ?string
    {
        $role = $user?->role ?? null;

        if ($role instanceof \BackedEnum) {
            $role = $role->value;
        }

        return filled($role) ? (string) $role : null;
    }

    private function resolve(string $feature, ?object $user, bool $write, ?string $action = null): bool
    {
        if ($user === null) {
            return false;
        }

        $role = $this->roleOf($user);

        // 1. Superadmin ha accesso su tutto
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if ($role !== null && in_array($role, (array) config('unico-core.access.deny_roles', []), true)) {
            return false;
        }

        // 3. Inspector come admin ma solo in lettura
        if ($write && $this->isReadOnly($user)) {
            return false;
        }

        $resource = Resource::query()->forCurrentApp()->where('key', $feature)->first();

        if ($resource === null) {
            return true;
        }

        if (! $this->plan($user)->covers($resource->min_plan)) {
            return false;
        }

        // 2. Admin vede tutto ciò che il piano della company copre (senza passare dai ruoli EmployeeType)
        if ($this->isAdmin($user)) {
            return true;
        }

        // 3. Inspector vede tutto ciò che il piano della company copre in lettura
        if ($this->isInspector($user)) {
            return true;
        }

        // 4. Ruoli di riferimento (employee, client, fornitore...) passano dai permessi EmployeeType
        $typeIds = $this->employeeTypeIds($user);

        if ($typeIds === []) {
            return true;
        }

        // Il preset sblocca tutte le azioni; la matrice granulare distingue lettura (viewAny, view) e scrittura (create, update, delete).
        if (EmployeeTypeResourcePreset::query()->whereIn('employee_type_id', $typeIds)->where('resource_id', $resource->getKey())->exists()) {
            return true;
        }

        $permissions = EmployeeTypePermission::query()->whereIn('employee_type_id', $typeIds)->where('resource_id', $resource->getKey());

        if ($write) {
            $permissions->whereIn('action', $action !== null ? [$action] : ['create', 'update', 'delete']);
        }

        return $permissions->exists();
    }

    private function canReferenceAccessModel(object $model, object $user, bool $write): bool
    {
        $role = $this->roleOf($user);
        $ref = $this->referenceModel($user);

        if ($ref === null) {
            $table = method_exists($model, 'getTable') ? $model->getTable() : null;

            return $table !== null && $this->check($table, null, $user, $write);
        }

        $refId = method_exists($ref, 'getKey') ? $ref->getKey() : ($ref->id ?? null);
        $userId = method_exists($user, 'getKey') ? $user->getKey() : ($user->id ?? null);

        // È il modello di riferimento stesso dell'utente
        $refClass = $this->referenceModelClass($role ?? '');
        if ($refClass !== null && $model instanceof $refClass) {
            $modelId = method_exists($model, 'getKey') ? $model->getKey() : ($model->id ?? null);

            return (string) $modelId === (string) $refId;
        }

        // Il modello punta direttamente al modello di riferimento o all'utente
        if ($role === 'employee') {
            if (isset($model->employee_id) && (string) $model->employee_id === (string) $refId) {
                return true;
            }
            if (isset($model->user_id) && (string) $model->user_id === (string) $userId) {
                return true;
            }
        } elseif ($role === 'client') {
            if (isset($model->client_id) && (string) $model->client_id === (string) $refId) {
                return true;
            }
            if (isset($model->user_id) && (string) $model->user_id === (string) $userId) {
                return true;
            }
        } elseif ($role === 'fornitore') {
            if (isset($model->fornitori_id) && (string) $model->fornitori_id === (string) $refId) {
                return true;
            }
            if (isset($model->fornitore_id) && (string) $model->fornitore_id === (string) $refId) {
                return true;
            }
            if (isset($model->agent_id) && (string) $model->agent_id === (string) $refId) {
                return true;
            }
            if (isset($model->user_id) && (string) $model->user_id === (string) $userId) {
                return true;
            }
        }

        // Altrimenti verifica i permessi EmployeeType dell'utente sulla risorsa corrispondente al modello
        $table = method_exists($model, 'getTable') ? $model->getTable() : null;
        if ($table !== null) {
            return $this->check($table, null, $user, $write);
        }

        return false;
    }

    private function planFromCompany(?object $user, PlanType $default): PlanType
    {
        $module = $this->companyModule($user);
        $plan = PlanType::parse($module?->plan_type, $default);

        if ($plan === PlanType::Trial && $module->trial_ends_at !== null && $module->trial_ends_at->endOfDay()->isPast()) {
            return PlanType::parse(config('unico-core.access.trial_expired_plan'), PlanType::Base);
        }

        return $plan;
    }

    private function decode(mixed $roles): mixed
    {
        return is_string($roles) ? json_decode($roles, true) : $roles;
    }

    private function currentUser(): ?object
    {
        return auth()->user();
    }
}
