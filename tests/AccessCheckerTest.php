<?php

namespace Unico\Core\Tests;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Unico\Core\Access\AccessChecker;
use Unico\Core\Access\PlanType;
use Unico\Core\Models\Company;
use Unico\Core\Models\CompanyModule;
use Unico\Core\Models\EmployeeType;
use Unico\Core\Models\EmployeeTypePermission;
use Unico\Core\Models\EmployeeTypeResourcePreset;
use Unico\Core\Models\Module;
use Unico\Core\Models\Resource;
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
        CoreDatabase::truncate('employee_type_resource_presets', 'employee_type_permissions', 'employee_types', 'resources', 'company_modules', 'modules', 'companies');
        config(['app.name' => 'Unico Loan', 'unico-core.access.plan' => null, 'unico-core.access.deny_roles' => [], 'unico-core.access.trial_expired_plan' => 'BASE']);
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
}
