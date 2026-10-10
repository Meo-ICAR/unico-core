<?php

namespace Unico\Core\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\TestCase;
use Unico\Core\Models\Company;
use Unico\Core\Models\Website;
use Unico\Core\MorphMap;
use Unico\Core\Tests\Support\CoreDatabase;

class WebsiteTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('websites', 'companies');
        Relation::morphMap(MorphMap::all(), merge: true);
    }

    private function site(array $attributes): Website
    {
        return Website::create($attributes + ['name' => 'Sito', 'domain' => 'www.example.it']);
    }

    public function test_a_site_belongs_to_a_company_through_the_morph(): void
    {
        $company = Company::create(['name' => 'Acme']);

        $site = $this->site(['company_id' => $company->id, 'websiteable_type' => 'company', 'websiteable_id' => $company->id]);

        $fresh = Website::find($site->id);
        $this->assertTrue($fresh->websiteable->is($company));
        $this->assertTrue($fresh->company->is($company));
        $this->assertSame('website', (new Website)->getMorphClass());
    }

    public function test_the_owner_id_can_be_an_external_uuid(): void
    {
        $uuid = '45d36df8-369f-40ce-b4fd-b5907c342fe9';

        $site = $this->site(['websiteable_type' => 'fornitore', 'websiteable_id' => $uuid, 'clienti_id' => $uuid]);

        $this->assertSame($uuid, $site->fresh()->websiteable_id);
        $this->assertSame($uuid, $site->fresh()->clienti_id);
    }

    public function test_flags_dates_and_soft_delete(): void
    {
        $site = $this->site([
            'websiteable_type' => 'company', 'websiteable_id' => 1,
            'is_footercompilant' => 1, 'privacy_date' => '2026-03-01',
        ]);

        $fresh = $site->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertTrue($fresh->is_typical);
        $this->assertTrue($fresh->is_footercompilant);
        $this->assertFalse($fresh->is_iso27001_certified);
        $this->assertSame('2026-03-01', $fresh->privacy_date->toDateString());

        $site->delete();
        $this->assertNull(Website::find($site->id));
        $this->assertNotNull(Website::withTrashed()->find($site->id));
    }

    public function test_deleting_the_company_keeps_the_site(): void
    {
        $company = Company::create(['name' => 'Acme']);
        $site = $this->site(['company_id' => $company->id, 'websiteable_type' => 'company', 'websiteable_id' => $company->id]);

        Company::query()->whereKey($company->id)->forceDelete();

        $this->assertNull($site->fresh()->company_id);
    }
}
