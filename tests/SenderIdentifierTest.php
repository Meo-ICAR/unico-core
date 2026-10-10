<?php

namespace Unico\Core\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Unico\Core\Mail\Contracts\SenderMatcher;
use Unico\Core\Mail\SenderIdentifier;
use Unico\Core\Models\Client;
use Unico\Core\Models\Company;
use Unico\Core\Models\ComplaintRegistry;
use Unico\Core\Models\Employee;
use Unico\Core\Models\Fornitore;
use Unico\Core\MorphMap;
use Unico\Core\Tests\Support\CoreDatabase;

class SenderIdentifierTest extends TestCase
{
    private int $company;

    private int $other;

    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('employees', 'clients', 'fornitoris', 'complaint_registry', 'companies');
        $this->company = Company::create(['name' => 'Acme'])->id;
        $this->other = Company::create(['name' => 'Beta'])->id;
        Relation::morphMap(MorphMap::all(), merge: true);
    }

    public function test_nothing_to_look_for_returns_nothing(): void
    {
        $this->assertCount(0, (new SenderIdentifier)->identify(null, '  '));
    }

    public function test_finds_an_employee_by_email_ignoring_case(): void
    {
        Employee::create(['company_id' => $this->company, 'name' => 'Mario Rossi', 'email' => 'Mario.Rossi@azienda.it']);

        $found = (new SenderIdentifier)->identify('MARIO.rossi@azienda.it');

        $this->assertCount(1, $found);
        $this->assertSame('employee', $found[0]['type']);
        $this->assertSame('Mario Rossi', $found[0]['label']);
        $this->assertSame('email', $found[0]['matched_on']);
    }

    public function test_a_longer_address_does_not_match_by_substring(): void
    {
        Employee::create(['company_id' => $this->company, 'name' => 'Mario', 'email' => 'mario.rossi@azienda.it']);

        $this->assertCount(0, (new SenderIdentifier)->identify('rossi@azienda.it'));
    }

    public function test_finds_one_address_among_several_in_the_same_field(): void
    {
        Client::create(['company_id' => $this->company, 'name' => 'Banca Alfa', 'dpo_email' => 'dpo@alfa.it / privacy@alfa.it, legale@alfa.it']);

        $found = (new SenderIdentifier)->identify('privacy@alfa.it');

        $this->assertCount(1, $found);
        $this->assertSame('client', $found[0]['type']);
        $this->assertSame('dpo_email', $found[0]['matched_on']);
    }

    public function test_company_filter_keeps_other_companies_out(): void
    {
        Employee::create(['company_id' => $this->company, 'name' => 'A', 'email' => 'a@x.it']);
        Employee::create(['company_id' => $this->other, 'name' => 'B', 'email' => 'a@x.it']);

        $found = (new SenderIdentifier)->identify('a@x.it', null, $this->other);

        $this->assertCount(1, $found);
        $this->assertSame('B', $found[0]['label']);
    }

    public function test_phone_matches_ignoring_spaces_and_country_prefix(): void
    {
        Fornitore::create(['company_id' => $this->company, 'name' => 'Fornitore Beta', 'tel' => '+39 333-123 4567']);

        $found = (new SenderIdentifier)->identify(null, '333 1234567');

        $this->assertCount(1, $found);
        $this->assertSame('fornitore', $found[0]['type']);
        $this->assertSame('tel', $found[0]['matched_on']);
    }

    public function test_complaint_registry_matches_on_receiving_email_and_labels_with_protocol(): void
    {
        ComplaintRegistry::create(['company_id' => $this->company, 'protocol_number' => 'R-001', 'description' => 'Reclamo', 'receiving_email' => 'reclami@banca.it']);

        $found = (new SenderIdentifier)->identify('reclami@banca.it');

        $this->assertSame('complaint_registry', $found[0]['type']);
        $this->assertSame('R-001', $found[0]['label']);
        $this->assertSame('receiving_email', $found[0]['matched_on']);
    }

    public function test_app_matchers_add_their_own_results(): void
    {
        $extra = new class implements SenderMatcher
        {
            public function match(?string $email, ?string $phone, int|string|null $companyId): Collection
            {
                return collect([['type' => 'data_subject_request', 'label' => 'Interessato', 'matched_on' => 'requester_email', 'model' => null]]);
            }
        };

        $found = (new SenderIdentifier([$extra]))->identify('chiunque@x.it');

        $this->assertSame(['data_subject_request'], $found->pluck('type')->all());
    }
}
