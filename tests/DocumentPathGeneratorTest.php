<?php

namespace Unico\Core\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\TestCase;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Unico\Core\Documents\DocumentPathGenerator;
use Unico\Core\Models\Audit;
use Unico\Core\Models\Branch;
use Unico\Core\Models\Company;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\Employee;
use Unico\Core\Models\TrainingRecord;
use Unico\Core\MorphMap;
use Unico\Core\Tests\Support\CoreDatabase;

class DocumentPathGeneratorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::boot();
    }

    protected function setUp(): void
    {
        Relation::morphMap(MorphMap::all(), merge: true);
    }

    private function path(?string $type, $documentable = null, ?string $company = 'Acme Spa', ?string $codegroup = null): string
    {
        $document = new Document(['documentable_type' => $type]);
        $document->setRelation('company', $company ? new Company(['name' => $company]) : null);
        $document->setRelation('documentable', $documentable);
        $document->setRelation('documentType', new DocumentType(['codegroup' => $codegroup]));
        $media = new Media;
        $media->setRelation('model', $document);

        return (new DocumentPathGenerator)->getPath($media);
    }

    public function test_company_level_documents_go_under_their_category(): void
    {
        $this->assertSame('acme-spa/trattamenti/', $this->path('company', null, 'Acme Spa', 'Trattamenti'));
        $this->assertSame('acme-spa/', $this->path('company'));
    }

    public function test_unknown_company_has_a_fallback_folder(): void
    {
        $this->assertSame('azienda-sconosciuta/', $this->path('company', null, null));
    }

    public function test_employee_documents_go_in_a_folder_named_after_the_person(): void
    {
        $this->assertSame('acme-spa/dipendenti/mario-rossi/', $this->path('employee', new Employee(['name' => 'Mario Rossi'])));
    }

    public function test_branch_documents_sit_directly_under_the_company(): void
    {
        $this->assertSame('acme-spa/sede-di-roma/', $this->path('branch', new Branch(['name' => 'Sede di Roma'])));
    }

    public function test_training_documents_sit_next_to_the_employee_certificate(): void
    {
        $record = new TrainingRecord;
        $record->setRelation('trainable', new Employee(['name' => 'Mario Rossi']));

        $this->assertSame('acme-spa/dipendenti/mario-rossi/training/', $this->path('training_record', $record));
        $this->assertSame('acme-spa/training/', $this->path('training_record', new TrainingRecord));
    }

    public function test_audit_evidence_is_grouped_by_audited_subject(): void
    {
        $audit = new Audit(['auditable_type' => 'fornitore']);
        $audit->setRelation('auditable', new Branch(['name' => 'Beta Srl']));

        $this->assertSame('acme-spa/reclami/fornitore/beta-srl/', $this->path('audit', $audit));
    }

    public function test_conversions_and_responsive_images_have_their_own_subfolders(): void
    {
        $document = new Document(['documentable_type' => 'company']);
        $document->setRelation('company', new Company(['name' => 'Acme']));
        $media = new Media;
        $media->setRelation('model', $document);
        $generator = new DocumentPathGenerator;

        $this->assertSame('acme/conversions/', $generator->getPathForConversions($media));
        $this->assertSame('acme/responsive-images/', $generator->getPathForResponsiveImages($media));
    }
}
