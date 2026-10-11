<?php

namespace Unico\Core\Tests;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentType;
use Unico\Core\Tests\Support\CoreDatabase;

class DocumentModelsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('document_analyses', 'documents', 'document_types');
        CoreDatabase::$auth->userId = null;
    }

    private function type(array $attributes = []): DocumentType
    {
        return DocumentType::create($attributes + [
            'name' => 'Tipo '.uniqid(),
            'code' => 'T'.uniqid(),
            'is_monitored' => true,
            'duration' => 12,
            'duration_unit' => 'months',
        ]);
    }

    private function document(DocumentType $type, array $attributes = []): Document
    {
        return Document::create($attributes + [
            'documentable_type' => 'company',
            'documentable_id' => 1,
            'document_type_id' => $type->id,
            'name' => 'Doc',
        ]);
    }

    public function test_every_status_has_label_color_and_icon(): void
    {
        foreach (DocumentStatus::cases() as $status) {
            $this->assertNotEmpty($status->getLabel());
            $this->assertNotEmpty($status->getColor());
            $this->assertStringStartsWith('heroicon-', $status->getIcon());
        }
        $this->assertSame(9, count(DocumentStatus::cases()));
    }

    public function test_duration_units(): void
    {
        $from = '2026-01-15';

        $this->assertSame('2026-01-25', $this->type(['duration' => 10, 'duration_unit' => 'days'])->durationCalculate($from)->toDateString());
        $this->assertSame('2026-04-15', $this->type(['duration' => 3, 'duration_unit' => 'months'])->durationCalculate($from)->toDateString());
        $this->assertSame('2028-01-15', $this->type(['duration' => 2, 'duration_unit' => 'years'])->durationCalculate($from)->toDateString());
        $this->assertSame('2026-01-16', $this->type(['duration' => 36, 'duration_unit' => 'hours'])->durationCalculate($from)->toDateString());
    }

    public function test_duration_end_of_month(): void
    {
        $type = $this->type(['duration' => 1, 'duration_unit' => 'months', 'is_end_month' => true]);
        $this->assertSame('2026-02-28', $type->durationCalculate('2026-01-15')->toDateString());

        $plain = $this->type(['duration' => 1, 'duration_unit' => 'months']);
        $this->assertSame('2026-02-15', $plain->durationCalculate('2026-01-15')->toDateString());
        $this->assertSame('2026-02-28', $plain->durationCalculate('2026-01-15', forceEndMonth: true)->toDateString());
    }

    public function test_duration_is_null_when_not_applicable(): void
    {
        $this->assertNull($this->type(['is_monitored' => false])->durationCalculate('2026-01-15'));
        $this->assertNull($this->type(['duration' => null])->durationCalculate('2026-01-15'));
        $this->assertNull($this->type(['duration_unit' => 'weeks'])->durationCalculate('2026-01-15'));
        $this->assertNull($this->type()->durationCalculate(null));
    }

    public function test_default_status_is_cast_to_enum(): void
    {
        $document = $this->document($this->type())->fresh();

        $this->assertSame(DocumentStatus::UPLOADED, $document->status);
    }

    public function test_requested_document_with_emission_date_becomes_approved(): void
    {
        $document = $this->document($this->type(), ['status' => 'richiesto', 'emitted_at' => '2026-01-15']);

        $this->assertSame(DocumentStatus::APPROVED, $document->fresh()->status);
    }

    public function test_requested_document_without_emission_date_stays_requested(): void
    {
        $document = $this->document($this->type(), ['status' => DocumentStatus::PENDING]);

        $this->assertSame(DocumentStatus::PENDING, $document->fresh()->status);
    }

    public function test_rejected_document_always_has_a_note(): void
    {
        $document = $this->document($this->type(), ['status' => DocumentStatus::REJECTED]);
        $this->assertSame('Nessuna nota fornita.', $document->fresh()->rejection_note);

        $kept = $this->document($this->type(), ['status' => DocumentStatus::REJECTED, 'rejection_note' => 'Foto sfocata']);
        $this->assertSame('Foto sfocata', $kept->fresh()->rejection_note);
    }

    public function test_expiry_is_computed_from_the_type(): void
    {
        $document = $this->document($this->type(), ['emitted_at' => '2026-01-15']);

        $this->assertSame('2027-01-15', $document->fresh()->expires_at->toDateString());
    }

    public function test_manual_expiry_is_never_overwritten(): void
    {
        $document = $this->document($this->type(), ['emitted_at' => '2026-01-15', 'expires_at' => '2026-12-31']);
        $this->assertSame('2026-12-31', $document->fresh()->expires_at->toDateString());

        $document->update(['description' => 'solo una nota']);
        $this->assertSame('2026-12-31', $document->fresh()->expires_at->toDateString());

        $document->update(['emitted_at' => '2026-03-01', 'expires_at' => '2027-06-30']);
        $this->assertSame('2027-06-30', $document->fresh()->expires_at->toDateString());
    }

    public function test_expiry_is_recomputed_when_only_emission_date_changes(): void
    {
        $document = $this->document($this->type(), ['emitted_at' => '2026-01-15']);

        $document->update(['emitted_at' => '2026-03-01']);

        $this->assertSame('2027-03-01', $document->fresh()->expires_at->toDateString());
    }

    public function test_document_end_month_flag_rounds_expiry(): void
    {
        $document = $this->document($this->type(), ['emitted_at' => '2026-01-15', 'is_end_month' => true]);

        $this->assertSame('2027-01-31', $document->fresh()->expires_at->toDateString());
    }

    public function test_audit_columns_follow_the_authenticated_user(): void
    {
        foreach ([7, 9] as $id) {
            CoreDatabase::$capsule->getConnection('core')->table('users')->insertOrIgnore([
                'id' => $id, 'name' => "U$id", 'email' => "u$id@example.test", 'password' => 'x',
            ]);
        }

        CoreDatabase::$auth->userId = 7;
        $document = $this->document($this->type());
        $this->assertSame(7, (int) $document->fresh()->created_by);

        CoreDatabase::$auth->userId = 9;
        $document->update(['name' => 'Rinominato']);
        $fresh = $document->fresh();
        $this->assertSame(7, (int) $fresh->created_by);
        $this->assertSame(9, (int) $fresh->updated_by);
    }

    public function test_renew_creates_new_document_and_expires_the_old_one(): void
    {
        $type = $this->type();
        $old = $this->document($type, ['emitted_at' => '2025-01-15', 'status' => DocumentStatus::APPROVED]);

        $new = $old->renew('2026-02-01');

        $old = $old->fresh();
        $this->assertSame(DocumentStatus::EXPIRED, $old->status);
        $this->assertSame($new->id, (int) $old->replaced_by_id);
        $this->assertSame(DocumentStatus::APPROVED, $new->fresh()->status);
        $this->assertSame('2026-02-01', $new->fresh()->emitted_at->toDateString());
        $this->assertSame('2027-02-01', $new->fresh()->expires_at->toDateString());
        $this->assertSame($new->id, $old->replacedBy->id);
        $this->assertSame($old->id, $new->replaces->id);
        $this->assertSame($old->expires_at->toDateString(), '2026-01-15');
    }

    public function test_renewing_type_is_the_linked_one_not_the_first(): void
    {
        $first = $this->type(['name' => 'Primo']);
        $newer = $this->type(['name' => 'Rinnovo']);
        $old = $this->type(['name' => 'Vecchio', 'renewed_by_id' => $newer->id]);

        $document = $this->document($old);

        $this->assertSame('Rinnovo', $document->renewingType()->name);
        $this->assertNull($this->document($first)->renewingType());
    }

    public function test_current_scope_hides_expired_revoked_and_replaced(): void
    {
        $type = $this->type();
        $ok = $this->document($type, ['status' => DocumentStatus::APPROVED]);
        $this->document($type, ['status' => DocumentStatus::EXPIRED]);
        $this->document($type, ['status' => DocumentStatus::REVOKED]);
        $old = $this->document($type, ['status' => DocumentStatus::APPROVED, 'emitted_at' => '2025-01-01']);
        $old->renew('2026-01-01');

        $ids = Document::query()->current()->orderBy('id')->pluck('id')->all();

        $this->assertContains($ok->id, $ids);
        $this->assertNotContains($old->id, $ids);
        $this->assertCount(2, $ids); // $ok + il rinnovo di $old
    }

    public function test_expiring_within_scope(): void
    {
        $type = $this->type(['is_monitored' => false]);
        $soon = $this->document($type, ['expires_at' => now()->addDays(10)]);
        $this->document($type, ['expires_at' => now()->addDays(90)]);
        $this->document($type);

        $this->assertSame([$soon->id], Document::query()->expiringWithin(30)->pluck('id')->all());
    }

    public function test_latest_per_type_until_picks_one_document_per_type(): void
    {
        $a = $this->type(['is_monitored' => false]);
        $b = $this->type(['is_monitored' => false]);
        $this->document($a, ['emitted_at' => '2025-03-01']);
        $newestA = $this->document($a, ['emitted_at' => '2025-09-01']);
        $this->document($a, ['emitted_at' => '2026-02-01']); // dopo la fine del semestre
        $onlyB = $this->document($b, ['emitted_at' => '2025-05-01']);

        $semester = (object) ['end' => Carbon::parse('2025-12-31')];
        $ids = Document::query()->perSemestreOam($semester)->orderBy('id')->pluck('id')->all();

        $this->assertSame([$newestA->id, $onlyB->id], $ids);
    }

    public function test_resolved_url_falls_back_to_metadata(): void
    {
        $type = $this->type();

        $this->assertSame('https://x.test/a', $this->document($type, ['document_url' => 'https://x.test/a'])->resolved_url);
        $this->assertSame('https://x.test/b', $this->document($type, ['metadata' => ['web_url' => 'https://x.test/b']])->resolved_url);
        $this->assertNull($this->document($type)->resolved_url);
    }

    public function test_long_document_url_is_stored_whole(): void
    {
        $url = 'https://tenant.sharepoint.com/'.str_repeat('a', 700);

        $this->assertSame($url, $this->document($this->type(), ['document_url' => $url])->fresh()->document_url);
    }

    public function test_current_media_prefers_the_signed_version(): void
    {
        $document = new class extends Document
        {
            public array $files = [];

            public function getFirstMedia(string $collectionName = 'default', $filters = []): ?\Spatie\MediaLibrary\MediaCollections\Models\Media
            {
                return $this->files[$collectionName] ?? null;
            }
        };
        $original = new \Spatie\MediaLibrary\MediaCollections\Models\Media(['file_name' => 'originale.pdf']);
        $signed = new \Spatie\MediaLibrary\MediaCollections\Models\Media(['file_name' => 'firmato.pdf']);

        $this->assertNull($document->currentMedia());

        $document->files = [Document::COLLECTION => $original];
        $this->assertSame('originale.pdf', $document->currentMedia()->file_name);

        $document->files[Document::COLLECTION_SIGNED] = $signed;
        $this->assertSame('firmato.pdf', $document->currentMedia()->file_name);
    }

    public function test_the_latest_verification_and_its_scope(): void
    {
        $type = \Unico\Core\Models\DocumentType::create(['name' => 'Tipo', 'code' => 'T'.uniqid()]);
        $make = fn () => Document::create(['documentable_type' => 'company', 'documentable_id' => 1, 'document_type_id' => $type->id, 'name' => 'D'.uniqid()]);
        $failed = $make();
        $recovered = $make();
        $none = $make();
        foreach ([[$failed, 'failed'], [$recovered, 'failed'], [$recovered, 'passed']] as [$doc, $outcome]) {
            \Unico\Core\Models\DocumentAnalysis::create(['document_id' => $doc->id, 'kind' => 'verification', 'outcome' => $outcome]);
        }
        \Unico\Core\Models\DocumentAnalysis::create(['document_id' => $none->id, 'kind' => 'extraction', 'outcome' => 'failed']);

        $this->assertSame('failed', $failed->latestVerification()->outcome);
        $this->assertSame('passed', $recovered->latestVerification()->outcome);
        $this->assertNull($none->latestVerification());
        $this->assertSame([$failed->id], Document::query()->whereLatestVerification(['failed'])->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$recovered->id], Document::query()->whereLatestVerification(['passed'])->pluck('id')->all());
    }

    public function test_the_activity_model_uses_the_core_connection(): void
    {
        config(['unico-core.connection' => 'core']);

        $this->assertSame('core', (new \Unico\Core\Models\Activity)->getConnectionName());
        $this->assertSame('activity_log', (new \Unico\Core\Models\Activity)->getTable());
    }
}
