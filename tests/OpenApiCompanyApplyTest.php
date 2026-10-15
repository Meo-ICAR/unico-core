<?php

namespace Unico\Core\Tests;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;
use Unico\Core\Clients\OpenApiCompanyService;
use Unico\Core\Models\Client;
use Unico\Core\Models\ClientRelation;
use Unico\Core\Models\Company;
use Unico\Core\MorphMap;
use Unico\Core\Tests\Support\CoreDatabase;

class OpenApiCompanyApplyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();

        $schema = CoreDatabase::$capsule->getConnection('core')->getSchemaBuilder();
        if (! $schema->hasTable('client_relations')) {
            $schema->create('client_relations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('client_id');
                $table->unsignedBigInteger('client_type_id')->nullable();
                $table->decimal('shares_percentage', 5, 2)->nullable();
                $table->boolean('is_titolare')->default(false);
                $table->date('data_inizio_ruolo')->nullable();
                $table->date('data_fine_ruolo')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function setUp(): void
    {
        CoreDatabase::boot();
        Relation::morphMap(MorphMap::all(), merge: true);
        $app = Container::getInstance();
        $app->instance(Factory::class, new Factory);
        Facade::clearResolvedInstance(Factory::class);
        config([
            'unico-core.openapi.token' => 'token-di-test',
            'unico-core.openapi.company_url' => 'https://company.openapi.com',
            'unico-core.openapi.timeout' => 5,
        ]);
        Http::preventStrayRequests();
        CoreDatabase::truncate('client_relations', 'branches', 'clients', 'companies');
    }

    public function test_applica_il_response_advanced_su_azienda_e_soci(): void
    {
        $payload = json_decode((string) file_get_contents(__DIR__.'/Fixtures/openapi-it-advanced.json'), true);
        Http::fake([
            'https://company.openapi.com/IT-advanced/09006331210' => Http::response($payload),
        ]);

        $tenant = Company::create(['name' => 'Tenant']);
        $company = Client::create([
            'company_id' => $tenant->id,
            'name' => 'Hassisto',
            'is_person' => false,
            'tax_code' => '09006331210',
        ]);

        $result = (new OpenApiCompanyService)->apply($company);

        Http::assertSentCount(1);
        $this->assertSame(5, $result['outcome']['created']);
        $this->assertSame(5, $result['outcome']['linked']);

        $company->refresh();
        $this->assertFalse($company->is_person);
        $this->assertSame('HASSISTO S.R.L. *** START-UP COSTITUITA A NORMA DELL\'ART. 4 COMMA 10 BIS DEL DECRETO LEGGE 24 GENNAIO N. 3 ***', $company->name);
        $this->assertSame('09006331210', $company->vat_number);
        $this->assertSame('hassisto@pec.it', $company->pec);
        $this->assertSame('62.01', $company->ateco_code);
        $this->assertSame('NA 1001542', $company->cciaa_registration);
        $this->assertSame("SOCIETA' A RESPONSABILITA' LIMITATA", $company->legal_form);
        $this->assertSame('KRRH6B9', $company->sdi_code);
        $this->assertSame('ATTIVA', $company->activity_status);
        $this->assertSame('2018-07-10', $company->company_started_at->toDateString());
        $this->assertSame(2025, $company->balance_year);
        $this->assertSame(1, $company->employees);
        $this->assertEquals(324998, $company->turnover);
        $this->assertEquals(-5074, $company->net_worth);
        $this->assertEquals(10000, $company->share_capital);
        $this->assertNotNull($company->registry_updated_at);

        $branch = $company->branches()->sole();
        $this->assertTrue($branch->is_main_office);
        $this->assertSame('client', $branch->branchable_type);
        $this->assertSame('VIA MERCATO ITTICO 16', $branch->address);
        $this->assertSame('16', $branch->street_number);
        $this->assertSame(['MUGNANO DI NAPOLI', '80018', 'NA', 'CAMPANIA'], [$branch->city, $branch->zip_code, $branch->province, $branch->region]);

        $expected = [
            'MEOPGS64D04F839I' => ['MEO', 'PIER GIUSEPPE', '1964-04-04', 'M', 35],
            'PLMGPP82S13D862A' => ['PALMA', 'GIUSEPPE', '1982-11-13', 'M', 15],
            'MGLMRA64E10F839W' => ['MAGLIULO', 'MARIO', '1964-05-10', 'M', 20],
            'SSCRST73C26F839R' => ['SOSCIA', 'ERNESTO', '1973-03-26', 'M', 15],
            'RLNLBN71H61F839W' => ['ORLANDO', 'ALBINA', '1971-06-21', 'F', 15],
        ];

        $this->assertSame(6, Client::query()->count());
        $this->assertCount(5, $expected);

        foreach ($expected as $taxCode => [$surname, $firstName, $birth, $sex, $percent]) {
            $person = Client::query()->where('tax_code', $taxCode)->sole();
            $this->assertTrue($person->is_person);
            $this->assertSame($tenant->id, $person->company_id);
            $this->assertSame([$surname, $firstName, $birth, $sex], [$person->name, $person->first_name, $person->birth_date->toDateString(), $person->sex]);
            $relation = ClientRelation::query()->where('company_id', $company->id)->where('client_id', $person->id)->sole();
            $this->assertEquals($percent, $relation->shares_percentage);
            $this->assertFalse($relation->is_titolare);
        }

        $meo = Client::query()->where('tax_code', 'MEOPGS64D04F839I')->sole();
        $this->assertSame($meo->id, $company->legal_representative_id);

        $again = (new OpenApiCompanyService)->apply($company->fresh());
        $this->assertSame(['created' => 0, 'linked' => 0], $again['outcome']);
        $this->assertSame(6, Client::query()->count());
        $this->assertSame(5, ClientRelation::query()->where('company_id', $company->id)->count());
    }
}
