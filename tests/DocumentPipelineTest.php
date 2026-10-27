<?php

namespace Unico\Core\Tests;

use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Unico\Core\Documents\AnthropicDocumentReader;
use Unico\Core\Documents\CheckRegistry;
use Unico\Core\Documents\CheckResult;
use Unico\Core\Documents\Contracts\AnalysisGate;
use Unico\Core\Documents\Contracts\DeclaredData;
use Unico\Core\Documents\Contracts\DocumentCheck;
use Unico\Core\Documents\DocumentClassifier;
use Unico\Core\Documents\DocumentContext;
use Unico\Core\Documents\DocumentFile;
use Unico\Core\Documents\DocumentPipeline;
use Unico\Core\Documents\NullDeclaredData;
use Unico\Core\Documents\OpenGate;
use Unico\Core\Documents\TechnicalVerifier;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Events\DocumentAnalyzed;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentAnalysis;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\SignatureRequest;
use Unico\Core\Tests\Support\CoreDatabase;
use Unico\Core\Tests\Support\FakeFiles;
use Unico\Core\Tests\Support\FakeReader;

class DocumentPipelineTest extends TestCase
{
    private FakeFiles $files;

    private FakeReader $reader;

    private array $declared = [];

    private bool $gateOpen = true;

    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('document_analyses', 'signature_requests', 'documents', 'document_types');
        CoreDatabase::$auth->userId = null;
        config(['unico-core.documents.ai.price_input' => 3, 'unico-core.documents.ai.price_output' => 15]);
        config(['unico-core.documents.checks' => (require __DIR__.'/../config/unico-core.php')['documents']['checks']]);
        config(['unico-core.documents.default_checks' => (require __DIR__.'/../config/unico-core.php')['documents']['default_checks']]);

        $this->files = new FakeFiles;
        $this->reader = new FakeReader;
        $this->declared = [];
        $this->gateOpen = true;
    }

    private function pipeline(): DocumentPipeline
    {
        $declared = $this;
        $registry = new CheckRegistry(Container::getInstance());

        return new DocumentPipeline(
            $this->files,
            new TechnicalVerifier,
            $this->reader,
            new DocumentClassifier($this->reader),
            $registry,
            new class($declared) implements DeclaredData
            {
                public function __construct(private DocumentPipelineTest $t) {}

                public function for(Document $document): array
                {
                    return $this->t->declaredData();
                }
            },
            new class($declared) implements AnalysisGate
            {
                public function __construct(private DocumentPipelineTest $t) {}

                public function allows(Document $document, string $kind): bool
                {
                    return $this->t->gateOpen();
                }
            },
        );
    }

    public function declaredData(): array
    {
        return $this->declared;
    }

    public function gateOpen(): bool
    {
        return $this->gateOpen;
    }

    private function type(array $attributes = []): DocumentType
    {
        return DocumentType::create($attributes + ['name' => 'Tipo '.uniqid(), 'code' => 'T'.uniqid()]);
    }

    private function identityType(array $attributes = []): DocumentType
    {
        return $this->type($attributes + ['name' => 'Documento identità', 'ai_kind' => 'identita']);
    }

    private function document(?DocumentType $type, ?DocumentFile $file, array $attributes = []): Document
    {
        $document = Document::create($attributes + [
            'documentable_type' => 'company',
            'documentable_id' => 1,
            'document_type_id' => $type?->id,
            'name' => 'Doc',
        ]);
        $this->files->files[$document->id] = $file;

        return $document;
    }

    private function jpeg(string $content = 'immagine'): DocumentFile
    {
        return new DocumentFile($content, 'image/jpeg', 'foto.jpg');
    }

    private function idFields(array $override = []): array
    {
        return $override + [
            'kind_detected' => 'identita', 'legible' => true, 'surname' => 'Rossi', 'name' => 'Mario',
            'fiscal_code' => 'RSSMRA80A01H501U', 'birth_date' => '01/01/1980', 'document_number' => 'AB123',
            'expiry_date' => '31/12/2035',
        ];
    }

    // --- Verifica tecnica ---

    public function test_missing_file_is_flagged_and_stops_everything(): void
    {
        $document = $this->document($this->identityType(), null, ['status' => DocumentStatus::PENDING]);

        $result = $this->pipeline()->run($document);

        $this->assertSame('failed', $result->outcome);
        $this->assertSame(['missing_file'], $result->analyses[0]->result['anomalies']);
        $this->assertCount(1, $result->analyses);
        $this->assertSame(0, $this->reader->reads);
        $this->assertSame(DocumentStatus::UPLOADED, $document->fresh()->status);
    }

    public function test_remote_url_without_local_file_is_not_a_missing_file(): void
    {
        $document = $this->document($this->type(), null, ['document_url' => 'https://example.sharepoint.com/x']);

        $result = $this->pipeline()->run($document);

        $this->assertSame([], $result->analyses[0]->result['anomalies']);
        $this->assertSame('passed', $result->outcome);
    }

    public function test_empty_and_fake_pdf_files_are_unreadable(): void
    {
        $empty = $this->document($this->type(), new DocumentFile('', 'image/png', 'a.png'));
        $fake = $this->document($this->type(), new DocumentFile('non sono un pdf', 'application/pdf', 'a.pdf'));

        foreach ([$empty, $fake] as $document) {
            $result = $this->pipeline()->run($document);
            $this->assertSame(['unreadable'], $result->analyses[0]->result['anomalies']);
            $this->assertNull($document->fresh()->file_hash);
        }
        $this->assertSame(0, $this->reader->reads);
    }

    public function test_hash_is_stored_and_duplicates_skip_the_ai(): void
    {
        $type = $this->identityType();
        $first = $this->document($type, $this->jpeg('stesso'));
        $second = $this->document($type, $this->jpeg('stesso'));
        $this->reader->fields = $this->idFields();

        $this->pipeline()->run($first);
        $this->assertSame(hash('sha256', 'stesso'), $first->fresh()->file_hash);
        $this->assertSame(1, $this->reader->reads);

        $result = $this->pipeline()->run($second);

        $this->assertSame(['duplicate'], $result->analyses[0]->result['anomalies']);
        $this->assertSame(1, $this->reader->reads, 'un duplicato non va riletto con l\'AI');
        $this->assertSame('failed', $result->outcome);
    }

    public function test_same_file_for_another_subject_is_not_a_duplicate(): void
    {
        $type = $this->type();
        $a = $this->document($type, $this->jpeg('x'), ['documentable_id' => 1]);
        $b = $this->document($type, $this->jpeg('x'), ['documentable_id' => 2]);

        $this->pipeline()->run($a);

        $this->assertSame([], $this->pipeline()->run($b)->analyses[0]->result['anomalies']);
    }

    public function test_expired_document_is_flagged(): void
    {
        $document = $this->document($this->type(), $this->jpeg(), ['expires_at' => Carbon::now()->subDay()->toDateString()]);

        $result = $this->pipeline()->run($document);

        $this->assertContains('expired', $result->analyses[0]->result['anomalies']);
        $this->assertSame('failed', $result->outcome);
    }

    public function test_signature_outcomes(): void
    {
        $type = $this->type(['is_signed' => true]);
        $pdf = fn (string $extra = '') => new DocumentFile('%PDF-1.4 '.$extra, 'application/pdf', 'm.pdf');

        $handwritten = $this->pipeline()->run($this->document($type, $pdf('a')));
        $this->assertSame('olografa', $handwritten->analyses[0]->result['signature']);
        $this->assertSame('needs_review', $handwritten->outcome);

        $digital = $this->pipeline()->run($this->document($type, $pdf('/ByteRange /Sig')));
        $this->assertSame('digitale', $digital->analyses[0]->result['signature']);

        $missing = $this->pipeline()->run($this->document($type, null, ['document_url' => 'https://x.example/y']));
        $this->assertSame('assente', $missing->analyses[0]->result['signature']);
        $this->assertSame(['signature_missing'], $missing->analyses[0]->result['anomalies']);

        $pending = $this->document($type, $pdf('p'));
        SignatureRequest::create(['document_id' => $pending->id, 'provider' => 'yousign', 'status' => 'pending']);
        $this->assertSame('otp_in_attesa', $this->pipeline()->run($pending)->analyses[0]->result['signature']);

        $signed = $this->document($type, $pdf('s'));
        SignatureRequest::create(['document_id' => $signed->id, 'provider' => 'yousign', 'status' => 'signed']);
        $result = $this->pipeline()->run($signed);
        $this->assertSame('otp', $result->analyses[0]->result['signature']);
        $this->assertSame('passed', $result->outcome);

        $notRequired = $this->pipeline()->run($this->document($this->type(), $pdf('n')));
        $this->assertSame('non_richiesta', $notRequired->analyses[0]->result['signature']);
    }

    // --- Classificazione ---

    public function test_regex_on_filename_classifies_with_and_without_delimiters(): void
    {
        $bonifico = $this->type(['regex' => '/^bonifico/i']);
        $visura = $this->type(['regex' => 'visura\s+camerale']);

        $a = $this->document(null, new DocumentFile('x', 'application/pdf', 'x.pdf'));
        $this->files->files[$a->id] = new DocumentFile('%PDF a', 'application/pdf', 'Bonifico_marzo.pdf');
        $b = $this->document(null, new DocumentFile('%PDF b', 'application/pdf', 'VISURA CAMERALE 2026.pdf'));

        $this->assertSame($bonifico->id, $this->pipeline()->run($a)->document->document_type_id);
        $this->assertSame($visura->id, $this->pipeline()->run($b)->document->document_type_id);

        $row = DocumentAnalysis::where('document_id', $a->id)->where('kind', 'classification')->first();
        $this->assertSame('procedural', $row->operator_type);
        $this->assertSame(100, $row->confidence);
        $this->assertSame(0, $this->reader->classifications);
    }

    public function test_ambiguous_regex_falls_back_to_ai_and_respects_min_confidence(): void
    {
        $this->type(['regex' => 'doc']);
        $target = $this->type(['regex' => 'doc', 'is_ai_check' => true, 'ai_pattern' => 'una busta paga', 'min_confidence' => 80]);
        $document = $this->document(null, new DocumentFile('%PDF z', 'application/pdf', 'doc.pdf'));

        $this->reader->classification = ['type_id' => $target->id, 'confidence' => 75, 'abstract' => 'Busta paga'];
        $low = $this->pipeline()->run($document);
        $this->assertNull($low->document->document_type_id);
        $this->assertSame('needs_review', $low->outcome);
        $this->assertSame(1, $this->reader->classifications);

        $this->reader->classification = ['type_id' => $target->id, 'confidence' => 92, 'abstract' => 'Busta paga di marzo'];
        $high = $this->pipeline()->run($document->fresh());
        $fresh = $document->fresh();
        $this->assertSame($target->id, $fresh->document_type_id);
        $this->assertSame(92, $fresh->ai_confidence_score);
        $this->assertSame('Busta paga di marzo', $fresh->ai_abstract);

        $row = DocumentAnalysis::where('document_id', $document->id)->where('kind', 'classification')->orderByDesc('id')->first();
        $this->assertSame('ai', $row->operator_type);
        $this->assertSame('fake-model', $row->ai_model);
        $this->assertSame(0.006, (float) $row->getRawOriginal('ai_cost'));
    }

    public function test_ai_cannot_pick_a_type_outside_the_candidates(): void
    {
        $this->type(['is_ai_check' => true]);
        $outsider = $this->type();
        $document = $this->document(null, new DocumentFile('%PDF q', 'application/pdf', 'q.pdf'));
        $this->reader->classification = ['type_id' => $outsider->id, 'confidence' => 99, 'abstract' => null];

        $result = $this->pipeline()->run($document);

        $this->assertNull($result->document->document_type_id);
    }

    public function test_unclassified_document_stops_before_extraction(): void
    {
        $document = $this->document(null, $this->jpeg());

        $result = $this->pipeline()->run($document);

        $this->assertSame(0, $this->reader->reads);
        $this->assertSame('needs_review', $result->outcome);
    }

    // --- Lettura e controlli ---

    public function test_matching_identity_passes_and_proposes_missing_data(): void
    {
        $document = $this->document($this->identityType(), $this->jpeg());
        $this->reader->fields = $this->idFields();
        $this->declared = ['cognome' => 'ROSSI', 'nome' => 'Mario Luigi', 'codice_fiscale' => 'RSS MRA 80A01 H501U'];

        $result = $this->pipeline()->run($document);

        $this->assertSame('passed', $result->outcome);
        $this->assertSame([], $result->issues);
        $this->assertSame(['data_nascita' => '01/01/1980', 'documento_numero' => 'AB123', 'documento_scadenza' => '31/12/2035'], $result->proposals);

        $row = $result->analyses[1];
        $this->assertSame('extraction', $row->kind);
        $this->assertSame(['leggibile', 'tipo_documento', 'dati_coerenti', 'non_scaduto'], $row->checks);
        $this->assertSame('ai', $row->operator_type);
        $this->assertSame(1000, $row->ai_input_tokens);
        $this->assertSame(0.006, (float) $row->getRawOriginal('ai_cost'));
    }

    public function test_mismatch_fails_with_readable_issue_and_stops_at_first_failure(): void
    {
        $document = $this->document($this->identityType(), $this->jpeg());
        $this->reader->fields = $this->idFields(['surname' => 'Bianchi', 'expiry_date' => '01/01/2000']);
        $this->declared = ['cognome' => 'Rossi'];

        $result = $this->pipeline()->run($document);

        $this->assertSame('failed', $result->outcome);
        $this->assertCount(1, $result->issues);
        $this->assertStringContainsString('Cognome', $result->issues[0]);
        $this->assertSame([], $result->proposals);
        $this->assertSame(['leggibile', 'tipo_documento', 'dati_coerenti'], $result->analyses[1]->checks);
    }

    public function test_expired_identity_fails_the_expiry_check(): void
    {
        $document = $this->document($this->identityType(), $this->jpeg());
        $this->reader->fields = $this->idFields(['expiry_date' => '01/02/2020']);

        $result = $this->pipeline()->run($document);

        $this->assertStringContainsString('scaduto', $result->issues[0]);
    }

    public function test_wrong_kind_is_reported(): void
    {
        $document = $this->document($this->identityType(), $this->jpeg());
        $this->reader->fields = $this->idFields(['kind_detected' => 'reddito']);

        $this->assertStringContainsString('documento di reddito', $this->pipeline()->run($document)->issues[0]);

        $other = $this->document($this->identityType(), $this->jpeg('altro'));
        $this->reader->fields = $this->idFields(['kind_detected' => 'altro']);
        $this->assertStringContainsString('non è riconoscibile', $this->pipeline()->run($other)->issues[0]);
    }

    public function test_informativa_must_be_our_form_and_signed(): void
    {
        $type = $this->type(['name' => 'Informativa privacy', 'ai_kind' => 'informativa', 'is_signed' => true]);
        $fields = fn (array $overrides = []) => $overrides + ['legible' => true, 'kind_detected' => 'informativa', 'matches_template' => true, 'signed' => true];

        $this->reader->fields = $fields();
        $this->assertSame('passed', $this->pipeline()->run($this->document($type, $this->jpeg()))->analyses[1]->outcome);

        $this->reader->fields = $fields(['matches_template' => false]);
        $this->assertStringContainsString('non sembra il nostro modulo', $this->pipeline()->run($this->document($type, $this->jpeg('b')))->issues[0]);

        $this->reader->fields = $fields(['signed' => false]);
        $this->assertStringContainsString('non risulta firmata', $this->pipeline()->run($this->document($type, $this->jpeg('c')))->issues[0]);

        $this->reader->fields = $fields(['signed' => null]);
        $this->assertStringContainsString('non risulta firmata', $this->pipeline()->run($this->document($type, $this->jpeg('d')))->issues[0]);

        $this->reader->fields = $fields(['kind_detected' => 'reddito']);
        $this->assertStringContainsString('documento di reddito', $this->pipeline()->run($this->document($type, $this->jpeg('e')))->issues[0]);
    }

    public function test_illegible_photo_marks_the_document_unreadable(): void
    {
        $document = $this->document($this->identityType(), $this->jpeg());
        $this->reader->fields = $this->idFields(['legible' => false]);

        $result = $this->pipeline()->run($document);

        $this->assertSame('failed', $result->outcome);
        $this->assertSame(DocumentStatus::UNREADABLE, $document->fresh()->status);
    }

    public function test_type_checks_override_defaults_and_accept_parameters(): void
    {
        $type = $this->identityType(['checks' => [['name' => 'non_scaduto', 'today' => '2030-01-01'], 'leggibile']]);
        $document = $this->document($type, $this->jpeg());
        $this->reader->fields = $this->idFields(['expiry_date' => '31/12/2029']);

        $result = $this->pipeline()->run($document);

        $this->assertStringContainsString('scaduto', $result->issues[0]);
        $this->assertSame(['non_scaduto'], $result->analyses[1]->checks);
    }

    public function test_plugin_checks_can_be_added_by_the_app(): void
    {
        $registry = new CheckRegistry(Container::getInstance());
        $registry->extend('sempre_no', new class implements DocumentCheck
        {
            public function inspect(DocumentContext $context): CheckResult
            {
                return CheckResult::fail('Regola dell\'app non rispettata');
            }
        });

        $this->assertNotNull($registry->get('leggibile'));
        $this->assertNull($registry->get('inesistente'));
        $this->assertSame('Regola dell\'app non rispettata', $registry->get('sempre_no')->inspect(
            new DocumentContext(new Document, 'identita', fn () => []),
        )->issues[0]);
    }

    public function test_reader_failure_is_recorded_without_leaking_the_message(): void
    {
        $document = $this->document($this->identityType(), $this->jpeg());
        $this->reader->throws = true;

        $result = $this->pipeline()->run($document);

        $row = $result->analyses[1];
        $this->assertSame('needs_review', $row->outcome);
        $this->assertSame(\RuntimeException::class, $row->error);
        $this->assertFalse(str_contains((string) $row->error, 'dati personali'));
    }

    public function test_closed_gate_postpones_the_ai(): void
    {
        $document = $this->document($this->identityType(), $this->jpeg());
        $this->gateOpen = false;

        $result = $this->pipeline()->run($document);

        $this->assertSame(0, $this->reader->reads);
        $this->assertSame(['waiting' => 'gate'], $result->analyses[1]->result);
        $this->assertSame('needs_review', $result->outcome);
    }

    public function test_disabled_reader_still_verifies(): void
    {
        $document = $this->document($this->identityType(), $this->jpeg());
        $this->reader->enabled = false;

        $result = $this->pipeline()->run($document);

        $this->assertCount(1, $result->analyses);
        $this->assertSame('passed', $result->outcome);
    }

    public function test_auto_verification_approves_only_when_everything_passed(): void
    {
        $auto = $this->identityType(['allow_auto_verification' => true]);
        $this->reader->fields = $this->idFields();

        $ok = $this->document($auto, $this->jpeg('uno'));
        $this->pipeline()->run($ok);
        $this->assertSame(DocumentStatus::APPROVED, $ok->fresh()->status);
        $this->assertNotNull($ok->fresh()->verified_at);

        $this->declared = ['cognome' => 'Verdi'];
        $ko = $this->document($auto, $this->jpeg('due'));
        $this->pipeline()->run($ko);
        $this->assertSame(DocumentStatus::UPLOADED, $ko->fresh()->status);

        $manual = $this->document($this->identityType(), $this->jpeg('tre'));
        $this->declared = [];
        $this->pipeline()->run($manual);
        $this->assertSame(DocumentStatus::UPLOADED, $manual->fresh()->status, 'senza il flag decide sempre l\'operatore');
    }

    public function test_event_is_dispatched_with_the_result(): void
    {
        $seen = [];
        Container::getInstance()->make('events')->listen(DocumentAnalyzed::class, function (DocumentAnalyzed $e) use (&$seen) {
            $seen[] = $e->result->outcome;
        });

        $this->pipeline()->run($this->document($this->type(), $this->jpeg('evento')));

        $this->assertSame(['passed'], $seen);
    }

    public function test_null_defaults(): void
    {
        $this->assertSame([], (new NullDeclaredData)->for(new Document));
        $this->assertTrue((new OpenGate)->allows(new Document, 'identita'));
    }

    // --- Lettore Anthropic ---

    public function test_anthropic_reader_builds_the_request_and_parses_the_answer(): void
    {
        $sent = [];
        $reader = new AnthropicDocumentReader('chiave', 'modello-x', function (array $payload) use (&$sent): array {
            $sent[] = $payload;

            return [
                'content' => [['type' => 'text', 'text' => json_encode(['legible' => true, 'kind_detected' => 'identita', 'surname' => 'Rossi'])]],
                'usage' => ['input_tokens' => 1500, 'output_tokens' => 120],
                'stop_reason' => 'end_turn',
            ];
        });

        $fields = $reader->read('identita', 'application/pdf', 'PDFDATA');

        $this->assertSame('Rossi', $fields['surname']);
        $this->assertSame(['model' => 'modello-x', 'input_tokens' => 1500, 'output_tokens' => 120], $reader->lastUsage());
        $this->assertSame('modello-x', $sent[0]['model']);
        $content = $sent[0]['messages'][0]['content'];
        $this->assertSame('document', $content[0]['type']);
        $this->assertSame('application/pdf', $content[0]['source']['media_type']);
        $this->assertSame(base64_encode('PDFDATA'), $content[0]['source']['data']);
        $this->assertSame('json_schema', $sent[0]['output_config']['format']['type']);

        $reader->read('identita', 'image/png', 'PNG');
        $this->assertSame('image', $sent[1]['messages'][0]['content'][0]['type']);
    }

    public function test_anthropic_reader_reads_the_kyc_questionnaire_with_its_own_schema_and_no_union_types(): void
    {
        $sent = [];
        $reader = new AnthropicDocumentReader('chiave', 'modello-x', function (array $payload) use (&$sent): array {
            $sent[] = $payload;

            return ['content' => [['type' => 'text', 'text' => json_encode(['legible' => true, 'pep_status' => 'nessuna'])]], 'stop_reason' => 'end_turn'];
        });

        $fields = $reader->read('questionario_kyc', 'application/pdf', 'PDF');

        $this->assertSame('nessuna', $fields['pep_status']);
        $this->assertStringContainsString('anti-money-laundering questionnaire', $sent[0]['system']);
        $properties = $sent[0]['output_config']['format']['schema']['properties'];
        $this->assertSame(['carica_pubblica', 'familiare', 'stretti_legami', 'carica_locale', 'nessuna', ''], $properties['pep_status']['enum']);
        $this->assertArrayHasKey('address_city', $properties);

        // L'API accetta al massimo 16 campi con tipo multiplo o anyOf.
        $unions = array_filter($properties, fn (array $property): bool => is_array($property['type'] ?? null) || isset($property['anyOf']));
        $this->assertLessThanOrEqual(16, count($unions));

        // Anche il profilo base resta valido: nessun enum con tipo multiplo.
        $reader->read('identita', 'application/pdf', 'PDF');
        $documentType = $sent[1]['output_config']['format']['schema']['properties']['document_type'];
        $this->assertArrayHasKey('anyOf', $documentType);
        $this->assertArrayNotHasKey('enum', $documentType);
    }

    public function test_anthropic_reader_ignores_unsupported_files_refusals_and_garbage(): void
    {
        $calls = 0;
        $answer = ['content' => [['type' => 'text', 'text' => 'non json']], 'usage' => ['input_tokens' => 5, 'output_tokens' => 1]];
        $reader = new AnthropicDocumentReader('k', 'm', function () use (&$calls, &$answer): array {
            $calls++;

            return $answer;
        });

        $this->assertNull($reader->read('identita', 'application/zip', 'x'));
        $this->assertSame(0, $calls);

        $this->assertNull($reader->read('identita', 'image/png', 'x'));
        $this->assertSame(5, $reader->lastUsage()['input_tokens'], 'il consumo c\'è anche se la risposta non serve');

        $answer = ['content' => [['type' => 'text', 'text' => '{"legible":true}']], 'stop_reason' => 'refusal'];
        $this->assertNull($reader->read('identita', 'image/png', 'x'));

        $this->assertFalse((new AnthropicDocumentReader('', 'm'))->enabled());
        $this->assertTrue($reader->enabled());
    }

    public function test_anthropic_reader_classifies_with_clamped_confidence(): void
    {
        $sent = null;
        $reader = new AnthropicDocumentReader('k', 'm', function (array $payload) use (&$sent): array {
            $sent = $payload;

            return ['content' => [['type' => 'text', 'text' => '{"type_id":7,"confidence":140,"abstract":"Busta paga"}']]];
        });
        $candidates = collect([new DocumentType(['name' => 'Busta paga', 'ai_pattern' => 'cedolino'])]);
        $candidates->first()->id = 7;

        $answer = $reader->classify($candidates, 'image/jpeg', 'x');

        $this->assertSame(['type_id' => 7, 'confidence' => 100, 'abstract' => 'Busta paga'], $answer);
        $this->assertStringContainsString('type_id 7: "Busta paga" — cedolino', $sent['messages'][0]['content'][1]['text']);
    }
}
