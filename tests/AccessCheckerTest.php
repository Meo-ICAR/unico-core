<?php

namespace Unico\Core\Tests;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Unico\Core\Access\AccessChecker;
use Unico\Core\Access\PlanType;
use Unico\Core\Models\Company;
use Unico\Core\Models\CompanyModule;
use Unico\Core\Models\Employee;
use Unico\Core\Models\EmployeeType;
use Unico\Core\Models\EmployeeTypePermission;
use Unico\Core\Models\EmployeeTypeResourcePreset;
use Unico\Core\Models\Fornitore;
use Unico\Core\Models\Client;
use Unico\Core\Models\Module;
use Unico\Core\Models\Pratica;
use Unico\Core\Models\Resource;
use Unico\Core\Models\User;
use Unico\Core\Tests\Support\CoreDatabase;

class AccessCheckerTest extends TestCase
{
    private AccessChecker $access;

    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::boot();
        CoreDatabase::truncate('employee_type_resource_presets', 'employee_type_permissions', 'employee_types', 'resources', 'company_modules', 'modules', 'companies', 'pratiches', 'employees', 'clients', 'fornitoris', 'users');
        config([
            'app.name' => 'Unico Loan',
            'unico-core.access.plan' => null,
            'unico-core.access.deny_roles' => [],
            'unico-core.access.trial_expired_plan' => 'BASE',
            'unico-core.access.readonly_roles' => ['inspector'],
            'unico-core.access.superadmin_roles' => ['superadmin', 'super_admin'],
            'unico-core.access.admin_roles' => ['admin'],
            'unico-core.access.bypass_roles' => ['superadmin', 'super_admin'],
        ]);
        $this->access = new AccessChecker;
    }

    private function user(?Company $company = null, string $role = 'user', ?array $roles = null, int $id = 1): object
    {
        return new class($id, $company?->id, $role, $roles) {
            public ?object $profile = null;

            public function __construct(private int $id, public ?int $company_id, public string $role, ?array $roles)
            {
                $this->profile = $roles === null ? null : (object) ['employee_roles' => $roles, 'company_id' => $company_id];
            }

            public function getKey(): int
            {
                return $this->id;
            }
        };
    }

    private function plan(string $plan, ?string $trialEnds = null, string $module = 'UNICOLOAN'): Company
    {
        $company = Company::create(['name' => 'Azienda '.uniqid()]);
        $mod = Module::firstOrCreate(['code' => $module], ['name' => $module]);
        CompanyModule::create(['company_id' => $company->id, 'module_id' => $mod->id, 'plan_type' => $plan, 'trial_ends_at' => $trialEnds]);

        return $company;
    }

    private function resource(string $key, string $minPlan = 'BASE', string $app = 'Unico Loan'): Resource
    {
        return Resource::create(['app_name' => $app, 'key' => $key, 'name' => $key, 'min_plan' => $minPlan]);
    }

    public function test_il_piano_si_legge_per_company_e_per_app(): void
    {
        $company = $this->plan('FULL');
        $other = Company::create(['name' => 'Altra']);
        $mod = Module::firstOrCreate(['code' => 'UNICOOAM'], ['name' => 'OAM']);
        CompanyModule::create(['company_id' => $other->id, 'module_id' => $mod->id, 'plan_type' => 'BASE']);
        $this->resource('pratiche', 'FULL');

        $this->assertSame(PlanType::Full, $this->access->plan($this->user($company)));
        $this->assertTrue($this->access->check('pratiche', null, $this->user($company)));
        // La stessa company ha un piano per ogni app: qui non ha il modulo e vale il piano di ripiego.
        $this->assertSame(PlanType::Full, $this->access->plan($this->user($other, id: 2)));
    }

    public function test_un_piano_inferiore_non_copre_le_risorse_piu_alte(): void
    {
        $company = $this->plan('BASE');
        $this->resource('base', 'BASE');
        $this->resource('medium', 'MEDIUM');
        $this->resource('full', 'FULL');
        $user = $this->user($company);

        $this->assertTrue($this->access->check('base', null, $user));
        $this->assertFalse($this->access->check('medium', null, $user));
        $this->assertFalse($this->access->check('full', null, $user));
    }

    public function test_la_prova_sblocca_tutto_finche_non_scade(): void
    {
        $this->resource('full', 'FULL');
        $active = $this->plan('TRIAL', Carbon::today()->addDays(3)->toDateString());
        $lastDay = $this->plan('TRIAL', Carbon::today()->toDateString());
        $expired = $this->plan('TRIAL', Carbon::yesterday()->toDateString());
        $open = $this->plan('TRIAL');

        $this->assertSame(PlanType::Trial, $this->access->plan($this->user($active, id: 1)));
        $this->assertTrue($this->access->check('full', null, $this->user($active, id: 1)));
        $this->assertSame(PlanType::Trial, $this->access->plan($this->user($lastDay, id: 2)));
        $this->assertSame(PlanType::Trial, $this->access->plan($this->user($open, id: 4)));
        $this->assertSame(PlanType::Base, $this->access->plan($this->user($expired, id: 3)));
        $this->assertFalse($this->access->check('full', null, $this->user($expired, id: 3)));
        $this->assertNotNull($this->access->trialEndsAt($this->user($active, id: 1)));
        $this->assertNull($this->access->trialEndsAt($this->user($this->plan('FULL'), id: 5)));
    }

    public function test_dopo_la_prova_vale_il_piano_di_ripiego_configurato(): void
    {
        config(['unico-core.access.trial_expired_plan' => 'MEDIUM']);
        $this->resource('medium', 'MEDIUM');
        $expired = $this->plan('TRIAL', Carbon::yesterday()->toDateString());

        $this->assertTrue($this->access->check('medium', null, $this->user($expired)));
    }

    public function test_il_piano_forzato_vale_per_tutti_e_senza_modulo_si_usa_il_ripiego(): void
    {
        $this->resource('full', 'FULL');
        $company = $this->plan('BASE');
        config(['unico-core.access.plan' => 'full']);
        $this->assertTrue($this->access->check('full', null, $this->user($company)));

        $this->access->flush();
        config(['unico-core.access.plan' => null]);
        $this->assertFalse($this->access->check('full', null, $this->user($company)));
        $this->assertSame(PlanType::Full, $this->access->plan($this->user(null, id: 9)));
    }

    public function test_il_nome_dellapp_decide_modulo_e_risorse(): void
    {
        $company = $this->plan('BASE');
        $this->resource('altra-app', 'FULL', 'Unico OAM');

        // La risorsa è di un'altra app: non la riguarda.
        $this->assertTrue($this->access->check('altra-app', null, $this->user($company)));
        $this->assertSame('UNICOLOAN', $this->access->moduleCode());
    }

    public function test_admin_vede_tutto_e_i_ruoli_negati_mai(): void
    {
        $company = $this->plan('BASE');
        $this->resource('full', 'FULL');
        config(['unico-core.access.deny_roles' => ['agent']]);

        $this->assertTrue($this->access->check('full', null, $this->user($company, 'super_admin')));
        $this->assertFalse($this->access->check('full', null, $this->user($company, 'agent', id: 2)));
        $this->assertFalse($this->access->check('inesistente', null, $this->user($company, 'agent', id: 2)));
    }

    public function test_il_ruolo_employee_type_serve_un_permesso_o_un_preset(): void
    {
        $company = $this->plan('FULL');
        $pratiche = $this->resource('pratiche');
        $audit = $this->resource('audits');
        $contabile = EmployeeType::create(['name' => 'Contabile']);
        $revisore = EmployeeType::create(['name' => 'Revisore']);
        EmployeeTypePermission::create(['employee_type_id' => $contabile->id, 'resource_id' => $pratiche->id]);
        EmployeeTypeResourcePreset::create(['employee_type_id' => $revisore->id, 'resource_id' => $audit->id]);

        $accountant = $this->user($company, roles: ['Contabile'], id: 1);
        $this->assertTrue($this->access->check('pratiche', null, $accountant));
        $this->assertFalse($this->access->check('audits', null, $accountant));
        $this->assertTrue($this->access->check('audits', null, $this->user($company, roles: ['Revisore'], id: 2)));
        // Più ruoli si sommano; senza ruoli (o con ruoli sconosciuti) decide il piano.
        $this->assertTrue($this->access->check('audits', null, $this->user($company, roles: ['Contabile', 'Revisore'], id: 3)));
        $this->assertTrue($this->access->check('audits', null, $this->user($company, roles: [], id: 4)));
        $this->assertTrue($this->access->check('audits', null, $this->user($company, roles: ['Sconosciuto'], id: 5)));
    }

    public function test_i_ruoli_possono_arrivare_come_json(): void
    {
        $company = $this->plan('FULL');
        $pratiche = $this->resource('pratiche');
        $this->resource('audits');
        $type = EmployeeType::create(['name' => 'Contabile']);
        EmployeeTypePermission::create(['employee_type_id' => $type->id, 'resource_id' => $pratiche->id]);
        $user = $this->user($company);
        $user->profile = (object) ['employee_roles' => '["Contabile"]'];

        $this->assertTrue($this->access->check('pratiche', null, $user));
        $this->assertFalse($this->access->check('audits', null, $user));
    }

    public function test_il_piano_non_riconosciuto_vale_base_per_le_risorse(): void
    {
        $this->assertTrue(PlanType::Base->covers(null));
        $this->assertTrue(PlanType::Full->covers('MEDIUM'));
        $this->assertFalse(PlanType::Medium->covers('FULL'));
        $this->assertTrue(PlanType::Trial->covers('full'));
    }

    public function test_lispettore_vede_tutto_nel_piano_ma_non_modifica(): void
    {
        $company = $this->plan('MEDIUM');
        $this->resource('pratiche');
        $this->resource('premium', 'FULL');
        $audits = $this->resource('audits');
        $contabile = EmployeeType::create(['name' => 'Contabile']);
        EmployeeTypePermission::create(['employee_type_id' => $contabile->id, 'resource_id' => $audits->id]);
        // Con i ruoli EmployeeType un utente normale vedrebbe solo le risorse con permesso: l'ispettore le vede tutte.
        $inspector = $this->user($company, 'inspector', ['Contabile'], 1);
        $normal = $this->user($company, 'user', ['Contabile'], 2);

        $this->assertTrue($this->access->isReadOnly($inspector));
        $this->assertFalse($this->access->isReadOnly($normal));
        $this->assertTrue($this->access->check('pratiche', null, $inspector));
        $this->assertFalse($this->access->check('pratiche', null, $normal));
        // Resta dentro il piano della company.
        $this->assertFalse($this->access->check('premium', null, $inspector));
        // In scrittura mai, nemmeno dove vedrebbe.
        $this->assertFalse($this->access->check('pratiche', null, $inspector, write: true));
        $this->assertFalse($this->access->check('audits', null, $inspector, write: true));
        $this->assertTrue($this->access->check('audits', null, $normal, write: true));
    }

    public function test_i_ruoli_di_sola_lettura_si_configurano(): void
    {
        $company = $this->plan('FULL');
        $this->resource('pratiche');
        config(['unico-core.access.readonly_roles' => ['revisore']]);

        $this->assertTrue($this->access->isReadOnly($this->user($company, 'revisore')));
        $this->assertFalse($this->access->isReadOnly($this->user($company, 'inspector', id: 2)));
        $this->assertFalse($this->access->check('pratiche', null, $this->user($company, 'revisore'), write: true));
    }

    public function test_le_azioni_libere_spariscono_per_chi_e_in_sola_lettura(): void
    {
        $this->assertTrue(\Unico\Core\Access\ReadOnlyActions::allows('sendForSignature', false));
        $this->assertFalse(\Unico\Core\Access\ReadOnlyActions::allows('sendForSignature', true));
        $this->assertTrue(\Unico\Core\Access\ReadOnlyActions::allows('download', true));
    }

    public function test_superadmin_ha_accesso_su_tutto(): void
    {
        $companyA = $this->plan('BASE');
        $companyB = Company::create(['name' => 'Azienda B']);
        $this->resource('full', 'FULL');

        $superadmin = User::create([
            'name' => 'Super Admin User',
            'email' => 'superadmin@example.com',
            'password' => 'secret',
            'role' => 'superadmin',
        ]);

        $this->assertTrue($this->access->isSuperAdmin($superadmin));
        $this->assertFalse($this->access->isAdmin($superadmin));
        $this->assertFalse($this->access->isInspector($superadmin));

        // Ha accesso alle risorse anche con piano BASE (bypassa tutto)
        $this->assertTrue($this->access->check('full', null, $superadmin));
        $this->assertTrue($this->access->check('full', null, $superadmin, write: true));

        // Ha accesso a qualsiasi modello di qualsiasi company o senza company
        $empA = Employee::create(['user_id' => $superadmin->id, 'company_id' => $companyA->id, 'name' => 'Emp A']);
        $empB = Employee::create(['company_id' => $companyB->id, 'name' => 'Emp B']);
        $this->assertTrue($this->access->canAccessModel($empA, $superadmin, write: true));
        $this->assertTrue($this->access->canAccessModel($empB, $superadmin, write: true));
        $this->assertTrue($this->access->canAccessModel($companyA, $superadmin, write: true));
    }

    public function test_admin_ha_accesso_su_tutti_i_model_con_company(): void
    {
        $companyA = $this->plan('FULL');
        $companyB = Company::create(['name' => 'Azienda B']);
        $this->resource('pratiche', 'FULL');

        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'secret',
            'role' => 'admin',
            'company_id' => $companyA->id,
        ]);

        $this->assertFalse($this->access->isSuperAdmin($admin));
        $this->assertTrue($this->access->isAdmin($admin));
        $this->assertFalse($this->access->isInspector($admin));
        $this->assertFalse($this->access->isReadOnly($admin));

        // Può accedere alle risorse della sua company senza ruoli EmployeeType
        $this->assertTrue($this->access->check('pratiche', null, $admin));
        $this->assertTrue($this->access->check('pratiche', null, $admin, write: true));

        // Ha accesso su tutti i model appartenenti alla sua company
        $empA = Employee::create(['company_id' => $companyA->id, 'name' => 'Emp A']);
        $empB = Employee::create(['company_id' => $companyB->id, 'name' => 'Emp B']);
        $this->assertTrue($this->access->canAccessModel($empA, $admin, write: true));
        $this->assertTrue($this->access->canAccessModel($companyA, $admin, write: true));

        // Non ha accesso ai model di altre company
        $this->assertFalse($this->access->canAccessModel($empB, $admin, write: true));
        $this->assertFalse($this->access->canAccessModel($companyB, $admin, write: true));
    }

    public function test_inspector_come_admin_ma_solo_in_lettura(): void
    {
        $companyA = $this->plan('FULL');
        $companyB = Company::create(['name' => 'Azienda B']);
        $this->resource('pratiche', 'FULL');

        $inspector = User::create([
            'name' => 'Inspector User',
            'email' => 'inspector@example.com',
            'password' => 'secret',
            'role' => 'inspector',
            'company_id' => $companyA->id,
        ]);

        $this->assertFalse($this->access->isSuperAdmin($inspector));
        $this->assertFalse($this->access->isAdmin($inspector));
        $this->assertTrue($this->access->isInspector($inspector));
        $this->assertTrue($this->access->isReadOnly($inspector));

        // Può visualizzare le risorse coperte dal piano
        $this->assertTrue($this->access->check('pratiche', null, $inspector, write: false));
        // MA non può scrivere
        $this->assertFalse($this->access->check('pratiche', null, $inspector, write: true));

        // Ha accesso ai model della company solo in lettura
        $empA = Employee::create(['company_id' => $companyA->id, 'name' => 'Emp A']);
        $empB = Employee::create(['company_id' => $companyB->id, 'name' => 'Emp B']);
        $this->assertTrue($this->access->canAccessModel($empA, $inspector, write: false));
        $this->assertFalse($this->access->canAccessModel($empA, $inspector, write: true));

        // Non ha accesso ai model di altre company nemmeno in lettura
        $this->assertFalse($this->access->canAccessModel($empB, $inspector, write: false));
    }

    public function test_ruoli_di_riferimento_employee_client_fornitore(): void
    {
        $company = $this->plan('FULL');
        $this->resource('pratiche');
        $contabile = EmployeeType::create(['name' => 'Contabile']);
        EmployeeTypePermission::create(['employee_type_id' => $contabile->id, 'resource_id' => Resource::where('key', 'pratiche')->first()->id]);

        // 1. Employee
        $userEmp = User::create([
            'name' => 'Mario Rossi',
            'email' => 'mario@example.com',
            'password' => 'secret',
            'role' => 'employee',
        ]);
        $emp = Employee::create([
            'user_id' => $userEmp->id,
            'company_id' => $company->id,
            'name' => 'Mario Rossi',
            'employee_roles' => ['Contabile'],
        ]);

        $this->assertTrue($this->access->isReferenceRole($userEmp));
        $this->assertSame($emp->id, $this->access->referenceModel($userEmp)?->id);
        $this->assertSame($company->id, $this->access->companyIdOf($userEmp));
        $this->assertSame([$contabile->id], $this->access->employeeTypeIds($userEmp));
        $this->assertTrue($this->access->check('pratiche', null, $userEmp));

        // Può accedere al proprio modello dipendente
        $this->assertTrue($this->access->canAccessModel($emp, $userEmp));
        // Non può accedere al dipendente di un altro
        $otherEmp = Employee::create(['company_id' => $company->id, 'name' => 'Altro Dipendente']);
        $this->assertFalse($this->access->canAccessModel($otherEmp, $userEmp));

        // Relazione Eloquent sul modello User
        $this->assertSame($emp->id, $userEmp->employee->id);
        $this->assertSame($emp->id, $userEmp->referenceModel()->id);

        // 2. Client
        $userClient = User::create([
            'name' => 'Cliente Uno',
            'email' => 'cliente@example.com',
            'password' => 'secret',
            'role' => 'client',
        ]);
        $client = Client::create([
            'user_id' => $userClient->id,
            'company_id' => $company->id,
            'name' => 'Cliente Uno',
            'first_name' => 'Cliente',
            'last_name' => 'Uno',
        ]);
        $this->assertTrue($this->access->isReferenceRole($userClient));
        $this->assertSame($client->id, $this->access->referenceModel($userClient)?->id);
        $this->assertSame($company->id, $this->access->companyIdOf($userClient));
        $this->assertTrue($this->access->canAccessModel($client, $userClient));
        $this->assertSame($client->id, $userClient->client->id);
        $this->assertSame($client->id, $userClient->referenceModel()->id);

        // 3. Fornitore
        $userFornitore = User::create([
            'name' => 'Fornitore Uno',
            'email' => 'fornitore@example.com',
            'password' => 'secret',
            'role' => 'fornitore',
        ]);
        $fornitore = Fornitore::create([
            'user_id' => $userFornitore->id,
            'company_id' => $company->id,
            'name' => 'Fornitore SpA',
        ]);
        $this->assertTrue($this->access->isReferenceRole($userFornitore));
        $this->assertSame($fornitore->id, $this->access->referenceModel($userFornitore)?->id);
        $this->assertSame($company->id, $this->access->companyIdOf($userFornitore));
        $this->assertTrue($this->access->canAccessModel($fornitore, $userFornitore));
        $this->assertSame($fornitore->id, $userFornitore->fornitore->id);
        $this->assertSame($fornitore->id, $userFornitore->referenceModel()->id);
    }

    public function test_scoping_delle_query_per_ruoli(): void
    {
        $companyA = $this->plan('FULL');
        $companyB = Company::create(['name' => 'Company B']);

        $adminA = User::create(['name' => 'Admin A', 'email' => 'adminA@test.com', 'password' => 'secret', 'role' => 'admin', 'company_id' => $companyA->id]);
        $inspectorA = User::create(['name' => 'Insp A', 'email' => 'inspA@test.com', 'password' => 'secret', 'role' => 'inspector', 'company_id' => $companyA->id]);
        $superadmin = User::create(['name' => 'Super', 'email' => 'super@test.com', 'password' => 'secret', 'role' => 'superadmin']);

        $empUserA = User::create(['name' => 'Emp User', 'email' => 'empUser@test.com', 'password' => 'secret', 'role' => 'employee']);
        $empA1 = Employee::create(['user_id' => $empUserA->id, 'company_id' => $companyA->id, 'name' => 'Mario']);
        $empA2 = Employee::create(['company_id' => $companyA->id, 'name' => 'Luigi']);
        $empB1 = Employee::create(['company_id' => $companyB->id, 'name' => 'Giuseppe']);

        // Superadmin vede tutto
        $this->assertSame(3, $this->access->scopeModel(Employee::query(), $superadmin)->count());

        // Admin A vede solo i record di Company A
        $adminResults = $this->access->scopeModel(Employee::query(), $adminA)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$empA1->id, $empA2->id], $adminResults);

        // Inspector A vede solo i record di Company A in lettura
        $inspResults = $this->access->scopeModel(Employee::query(), $inspectorA)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$empA1->id, $empA2->id], $inspResults);

        // Inspector in scrittura ha risultato vuoto
        $this->assertSame(0, $this->access->scopeModel(Employee::query(), $inspectorA, write: true)->count());

        // Employee vede solo il proprio record
        $empResults = $this->access->scopeModel(Employee::query(), $empUserA)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$empA1->id], $empResults);
    }
}
