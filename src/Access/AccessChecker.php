<?php

namespace Unico\Core\Access;

use Illuminate\Support\Arr;
use Unico\Core\Models\CompanyModule;
use Unico\Core\Models\EmployeeType;
use Unico\Core\Models\EmployeeTypePermission;
use Unico\Core\Models\EmployeeTypeResourcePreset;
use Unico\Core\Models\Resource;

/**
 * Cosa può fare un utente in un'app, in tre passi:
 *  1. i ruoli di sistema `bypass_roles` (admin, super_admin) vedono sempre tutto; i `deny_roles` (es. gli agenti di unicoloan, che usano
 *     solo il loro portale) mai; i `readonly_roles` (l'ispettore) vedono tutto ciò che il piano della company copre, ma non possono
 *     scrivere: per loro il passo 3 non si applica e `$write = true` risponde sempre no;
 *  2. il piano della company per l'app corrente (`company_modules`: modulo = nome dell'app in maiuscolo e senza spazi, risorse =
 *     `resources.app_name` = nome dell'app) deve coprire il `min_plan` della risorsa;
 *  3. i ruoli EmployeeType del profilo dell'utente (`employee_roles` di employee, fornitore o client consulente) devono avere un permesso
 *     sulla risorsa o un preset di accesso totale. Senza ruoli, o per risorse non censite, decide solo il piano.
 *
 * Il piano si ricava a ogni richiesta (non resta in sessione): un cambio di licenza vale subito. `unico-core.access.plan` (PLAN_TYPE)
 * lo forza per tutte le company, per sviluppo e test. I risultati sono memorizzati per la durata della richiesta.
 */
class AccessChecker
{
    /** @var array<string, bool> */
    private array $checks = [];

    /** @var array<string, PlanType> */
    private array $plans = [];

    /** @var array<string, list<int>> */
    private array $roles = [];

    public function appName(): string
    {
        return (string) config('app.name');
    }

    /** Codice del modulo in `modules.code` (es. «Unico BPM» → UNICOBPM). */
    public function moduleCode(): string
    {
        return strtoupper(str_replace(' ', '', $this->appName()));
    }

    /** `$write`: la funzione modifica i dati (creare, modificare, eliminare, azioni): i ruoli di sola lettura non la ottengono mai. */
    public function check(string $feature, ?string $callerClass = null, ?object $user = null, bool $write = false): bool
    {
        $user ??= $this->currentUser();
        $key = $feature.'|'.($user?->getKey() ?? 'guest').'|'.$this->plan($user)->value.'|'.(int) $write;

        return $this->checks[$key] ??= $this->resolve($feature, $user, $write);
    }

    /** L'utente ha un ruolo di sola lettura (l'ispettore): vede, non modifica. */
    public function isReadOnly(?object $user = null): bool
    {
        $role = $this->roleOf($user ?? $this->currentUser());

        return $role !== null && in_array($role, (array) config('unico-core.access.readonly_roles', ['inspector']), true);
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
        $roles = Arr::wrap($this->decode($user?->profile?->employee_roles ?? null));

        if ($roles === []) {
            return [];
        }

        return $this->roles[implode('|', $roles)] ??= EmployeeType::query()->whereIn('name', $roles)->pluck('id')->all();
    }

    /** Dimentica i risultati memorizzati (test, cambi di licenza a metà richiesta). */
    public function flush(): void
    {
        $this->checks = $this->plans = $this->roles = [];
    }

    private function resolve(string $feature, ?object $user, bool $write): bool
    {
        $role = $this->roleOf($user);

        if ($role !== null && in_array($role, (array) config('unico-core.access.bypass_roles', ['admin', 'super_admin']), true)) {
            return true;
        }

        if ($role !== null && in_array($role, (array) config('unico-core.access.deny_roles', []), true)) {
            return false;
        }

        if ($role !== null && $write && in_array($role, (array) config('unico-core.access.readonly_roles', ['inspector']), true)) {
            return false;
        }

        $resource = Resource::query()->forCurrentApp()->where('key', $feature)->first();

        if ($resource === null) {
            return true;
        }

        if (! $this->plan($user)->covers($resource->min_plan)) {
            return false;
        }

        // L'ispettore vede tutto quello che il piano copre, senza passare dai ruoli EmployeeType.
        if ($this->isReadOnly($user)) {
            return true;
        }

        $typeIds = $this->employeeTypeIds($user);

        if ($typeIds === []) {
            return true;
        }

        return EmployeeTypePermission::query()->whereIn('employee_type_id', $typeIds)->where('resource_id', $resource->getKey())->exists()
            || EmployeeTypeResourcePreset::query()->whereIn('employee_type_id', $typeIds)->where('resource_id', $resource->getKey())->exists();
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

    private function companyIdOf(?object $user): int|string|null
    {
        return $user?->company_id ?? $user?->profile?->company_id ?? null;
    }

    private function roleOf(?object $user): ?string
    {
        $role = $user?->role ?? null;

        if ($role instanceof \BackedEnum) {
            $role = $role->value;
        }

        return filled($role) ? (string) $role : null;
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
