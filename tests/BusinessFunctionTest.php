<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Models\BusinessFunction;
use Unico\Core\Models\BusinessFunctionMember;
use Unico\Core\Models\Client;
use Unico\Core\Models\Employee;
use Unico\Core\Models\EmployeeType;
use Unico\Core\Tests\Support\CoreDatabase;

class BusinessFunctionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
        // Gli alias salvati in `member_type` (employee, client) sono quelli del core.
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap(\Unico\Core\MorphMap::all(), merge: true);
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('business_function_members', 'business_functions', 'employees', 'clients', 'employee_types');
    }

    public function test_una_funzione_ha_come_membri_dipendenti_e_consulenti_esterni_con_il_loro_ruolo(): void
    {
        $funzione = BusinessFunction::create(['code' => 'SEGR-RETE', 'name' => 'Segreteria di Rete']);
        $ruolo = EmployeeType::create(['name' => 'segretaria', 'companytype' => 'FINANCE', 'is_external' => false]);
        $dipendente = Employee::create(['name' => 'Dipendente Prova']);
        $consulente = Client::create(['name' => 'Consulente Prova', 'is_person' => true]);

        BusinessFunctionMember::create(['business_function_id' => $funzione->id, 'member_type' => 'employee', 'member_id' => $dipendente->id, 'employee_type_id' => $ruolo->id, 'is_manager' => true]);
        BusinessFunctionMember::create(['business_function_id' => $funzione->id, 'member_type' => 'client', 'member_id' => $consulente->id, 'employee_type_id' => $ruolo->id]);

        $this->assertSame([$dipendente->id], $funzione->employees()->pluck('employees.id')->all());
        $this->assertSame([$consulente->id], $funzione->clients()->pluck('clients.id')->all());
        $this->assertTrue((bool) $funzione->employees()->first()->pivot->is_manager);
        $this->assertSame('segretaria', $funzione->members()->first()->employeeType->name);
    }

    public function test_lo_stesso_membro_non_entra_due_volte_nella_stessa_funzione(): void
    {
        $funzione = BusinessFunction::create(['code' => 'CDA', 'name' => 'Consiglio di Amministrazione']);
        $dipendente = Employee::create(['name' => 'Dipendente Prova']);
        $dati = ['business_function_id' => $funzione->id, 'member_type' => 'employee', 'member_id' => $dipendente->id];

        BusinessFunctionMember::create($dati);

        $this->expectException(\Illuminate\Database\QueryException::class);
        BusinessFunctionMember::create($dati);
    }
}
