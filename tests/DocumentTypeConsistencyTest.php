<?php

namespace Unico\Core\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\TestCase;
use Unico\Core\Documents\Api\DocumentApiService;
use Unico\Core\Documents\DocumentTypeConsistency;
use Unico\Core\Models\DocumentType;
use Unico\Core\MorphMap;
use Unico\Core\Tests\Support\CoreDatabase;

class DocumentTypeConsistencyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
        Relation::morphMap(MorphMap::all(), merge: true);
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('document_types', 'pdf_modules');
    }

    private function type(array $attributes): DocumentType
    {
        return DocumentType::create($attributes + ['name' => 'Tipo '.uniqid(), 'code' => 'T'.uniqid()]);
    }

    private function problems(DocumentType $type): array
    {
        return (new DocumentTypeConsistency(new DocumentApiService))->problemsOf($type->fresh());
    }

    public function test_a_signed_document_is_signed_by_the_client_unless_told_otherwise_and_the_api_service_sets_the_source(): void
    {
        $this->assertSame('client', $this->type(['is_signed' => true])->signer_model);
        $this->assertSame('fornitore', $this->type(['is_signed' => true, 'signer_model' => 'fornitore'])->signer_model);

        $api = $this->type(['api_service' => 'openapi_company']);
        $this->assertSame(DocumentType::SOURCE_API, $api->source);

        $stampa = $this->type(['source' => DocumentType::SOURCE_STAMPA]);
        $this->assertTrue($stampa->is_template);
        $this->assertSame('template_fillable', $stampa->nature);
        $this->assertFalse($this->type(['source' => DocumentType::SOURCE_CLIENTE])->is_template);
    }

    public function test_the_check_reports_what_the_source_and_the_rules_need(): void
    {
        $this->assertContains('Fonte stampa senza modulo PDF attivo.', $this->problems($this->type(['source' => 'stampa'])));
        $this->assertContains('Fonte template senza URL né file del template.', $this->problems($this->type(['source' => 'template'])));
        $this->assertContains('Fonte API senza servizio API.', $this->problems($this->type(['source' => 'api'])));
        $this->assertContains('Scadenza monitorata senza durata: non si calcola expires_at.', $this->problems($this->type(['source' => 'cliente', 'is_monitored' => true])));
        $this->assertContains('Prevede l\'approvazione ma non la firma.', $this->problems($this->type(['source' => 'cliente', 'approver_model' => 'fornitore'])));
        $this->assertContains('Rinnovato da un tipo inesistente o da sé stesso.', $this->problems(tap($this->type(['source' => 'cliente']), fn (DocumentType $t) => $t->update(['renewed_by_id' => $t->id]))));
        $this->assertSame([], $this->problems($this->type(['source' => 'template', 'document_url' => 'https://example.com/informativa.pdf', 'is_signed' => true, 'approver_model' => 'fornitore'])));
    }

    public function test_the_client_scope_decides_who_the_document_applies_to(): void
    {
        $this->assertTrue($this->type(['client_scope' => 'persona_fisica'])->appliesToPerson(true));
        $this->assertFalse($this->type(['client_scope' => 'persona_fisica'])->appliesToPerson(false));
        $this->assertTrue($this->type(['client_scope' => 'persona_giuridica'])->appliesToPerson(false));
        $this->assertTrue($this->type([])->appliesToPerson(false));
    }
}
