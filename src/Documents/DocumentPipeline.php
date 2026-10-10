<?php

namespace Unico\Core\Documents;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;
use Unico\Core\Documents\Contracts\AnalysisGate;
use Unico\Core\Documents\Contracts\DeclaredData;
use Unico\Core\Documents\Contracts\DocumentFileSource;
use Unico\Core\Documents\Contracts\DocumentReader;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Events\DocumentAnalyzed;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentAnalysis;
use Unico\Core\Models\DocumentType;

/**
 * Pipeline unica di analisi di un documento, in tre passi che scrivono ciascuno una riga in `document_analyses`:
 *
 *  1. verification  — controlli tecnici senza AI (file, duplicato, scadenza, firma);
 *  2. classification — se il tipo manca (o se richiesto): regex sul nome file, poi AI sul contenuto;
 *  3. extraction    — lettura AI (una sola per documento) e controlli a plug-in sui dati letti.
 *
 * L'AI parte solo se il file è utilizzabile, non è un duplicato e il gate lo consente. Mai respinge da sola:
 * promuove ad approvato solo se il tipo ha `allow_auto_verification` e ogni passo è superato.
 *
 * Opzioni di `run()`: `classify` (bool, forza o esclude la classificazione), `candidates` (Collection di DocumentType),
 * `checks` (elenco di codici o ['name' => ..., parametri]), `declared` (dati noti, al posto del provider).
 */
class DocumentPipeline
{
    private const RANK = ['passed' => 0, 'needs_review' => 1, 'failed' => 2];

    /** Anomalie dopo le quali leggere con l'AI sarebbe inutile. */
    private const BLOCKING = ['missing_file', 'unreadable', 'duplicate'];

    public function __construct(
        private DocumentFileSource $files,
        private TechnicalVerifier $verifier,
        private DocumentReader $reader,
        private DocumentClassifier $classifier,
        private CheckRegistry $registry,
        private DeclaredData $declared,
        private AnalysisGate $gate,
    ) {}

    public function run(Document $document, array $options = []): PipelineResult
    {
        $file = $this->files->fetch($document);
        $analyses = [];
        $outcome = 'passed';
        $issues = [];
        $proposals = [];

        // 1. Verifica tecnica
        $verification = $this->verifier->check($document, $file);
        $analyses[] = $this->record($document, 'verification', $verification['outcome'], [
            'checks' => ['file', 'duplicate', 'expiry', 'signature'],
            'result' => ['anomalies' => $verification['anomalies'], 'signature' => $verification['signature'], 'signers' => $verification['signers'], 'hash' => $verification['hash']],
            'operator_type' => 'procedural',
            'operator_label' => 'verifier.technical',
        ]);
        $outcome = $this->worst($outcome, $verification['outcome']);
        $issues = array_map(fn (string $a) => \Unico\Core\Enums\DocumentAnomaly::from($a)->label(), $verification['anomalies']);

        if ($document->status === DocumentStatus::PENDING) {
            $document->status = DocumentStatus::UPLOADED;
        }

        $blocked = array_intersect(self::BLOCKING, $verification['anomalies']) !== [];

        if (! $blocked && $file !== null) {
            // 2. Classificazione
            $classify = $options['classify'] ?? ($document->document_type_id === null);

            if ($classify) {
                $classification = $this->classify($document, $file, $options['candidates'] ?? $this->candidates($document));
                $analyses[] = $classification['analysis'];
                $outcome = $this->worst($outcome, $classification['analysis']->outcome);

                if ($classification['stop']) {
                    $blocked = true;
                }
            }

            // 3. Lettura e controlli
            if (! $blocked) {
                $extraction = $this->extract($document, $file, $options);

                if ($extraction !== null) {
                    $analyses[] = $extraction['analysis'];
                    $outcome = $this->worst($outcome, $extraction['analysis']->outcome);
                    $issues = [...$issues, ...$extraction['issues']];
                    $proposals = $extraction['proposals'];
                }
            }
        }

        if ($outcome === 'passed' && $document->documentType?->allow_auto_verification && $document->status !== DocumentStatus::APPROVED) {
            $document->status = DocumentStatus::APPROVED;
            $document->verified_at = now();
        }

        $document->save();

        $result = new PipelineResult($document, $outcome, $analyses, $issues, $outcome === 'passed' ? $proposals : []);
        event(new DocumentAnalyzed($result));

        return $result;
    }

    /**
     * @param  Collection<int, DocumentType>  $candidates
     * @return array{analysis: DocumentAnalysis, stop: bool}
     */
    private function classify(Document $document, DocumentFile $file, Collection $candidates): array
    {
        $usage = null;

        try {
            $found = $this->classifier->classify($candidates, $file);
            $usage = $found->usage;
            $error = null;
        } catch (Throwable $e) {
            Log::warning('Classificazione documento non riuscita', ['document' => $document->getKey(), 'exception' => $e::class]);
            $found = new Classification(null);
            $usage = null;
            $error = $e::class;
        }

        if ($found->matched()) {
            $document->document_type_id = $found->type->getKey();
            $document->unsetRelation('documentType');
            $document->ai_confidence_score = $found->confidence;
            $document->ai_abstract = $found->abstract ?? $document->ai_abstract;
        }

        $outcome = $found->matched() ? 'passed' : 'needs_review';

        $analysis = $this->record($document, 'classification', $outcome, [
            'result' => ['document_type_id' => $found->type?->getKey(), 'code' => $found->type?->code, 'abstract' => $found->abstract, 'candidates' => $candidates->pluck('id')->all()],
            'confidence' => $found->confidence,
            'operator_type' => $found->operator ?? 'procedural',
            'operator_label' => $found->label ?? 'classifier',
            'error' => $error,
        ], $usage);

        // Senza un tipo non si può leggere né controllare: decide una persona.
        return ['analysis' => $analysis, 'stop' => ! $found->matched() && $document->document_type_id === null];
    }

    /** @return array{analysis: DocumentAnalysis, issues: list<string>, proposals: array<string,string>}|null */
    private function extract(Document $document, DocumentFile $file, array $options): ?array
    {
        $type = $document->documentType;
        $kind = $type?->ai_kind;

        if (! $kind || ! $this->reader->enabled()) {
            return null;
        }

        if (! $this->gate->allows($document, $kind)) {
            return [
                'analysis' => $this->record($document, 'extraction', 'needs_review', [
                    'result' => ['waiting' => 'gate'],
                    'operator_type' => 'procedural',
                    'operator_label' => 'gate',
                ]),
                'issues' => [],
                'proposals' => [],
            ];
        }

        $error = null;
        $context = new DocumentContext($document, $kind, function () use ($file, $kind, $document, &$error): ?array {
            try {
                return $this->reader->read($kind, $file->mime, $file->bytes);
            } catch (Throwable $e) {
                // Mai messaggi o contenuti nel log: possono contenere dati personali.
                Log::error('Lettura documento non riuscita', ['document' => $document->getKey(), 'exception' => $e::class]);
                $error = $e::class;

                return null;
            }
        }, $options['declared'] ?? $this->declared->for($document));

        $fields = $context->fields();
        $usage = $this->reader->lastUsage();
        $label = 'ai.'.($usage['model'] ?? 'reader');

        if ($fields === null) {
            return [
                'analysis' => $this->record($document, 'extraction', 'needs_review', [
                    'result' => ['fields' => null],
                    'operator_type' => 'ai',
                    'operator_label' => $label,
                    'error' => $error ?? 'Documento non leggibile dal lettore AI',
                ], $usage),
                'issues' => [],
                'proposals' => [],
            ];
        }

        [$ran, $issues, $proposals] = $this->inspect($context, $options['checks'] ?? $type->checks ?: $this->defaultChecks($kind));

        if ($issues !== [] && ! ($fields['legible'] ?? true)) {
            $document->status = DocumentStatus::UNREADABLE;
        }

        return [
            'analysis' => $this->record($document, 'extraction', $issues === [] ? 'passed' : 'failed', [
                'checks' => $ran,
                'result' => ['fields' => $fields, 'issues' => $issues, 'proposals' => $issues === [] ? $proposals : []],
                'operator_type' => 'ai',
                'operator_label' => $label,
            ], $usage),
            'issues' => $issues,
            'proposals' => $proposals,
        ];
    }

    /**
     * @param  array<int, string|array<string,mixed>>  $entries
     * @return array{0: list<string>, 1: list<string>, 2: array<string,string>} controlli eseguiti, problemi (si ferma al primo che fallisce), dati proposti
     */
    private function inspect(DocumentContext $context, array $entries): array
    {
        $ran = [];
        $proposals = [];

        foreach ($entries as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? '') : $entry;
            $check = $this->registry->get($name);

            if ($check === null) {
                Log::error('Controllo documento sconosciuto', ['check' => $name]);

                continue;
            }

            $ran[] = $name;
            $result = $check->inspect($context->withParams(is_array($entry) ? array_diff_key($entry, ['name' => 1]) : []));

            if (! $result->passed()) {
                return [$ran, $result->issues, []];
            }

            $proposals = $result->proposals + $proposals;
        }

        return [$ran, [], $proposals];
    }

    /** @return list<string> */
    private function defaultChecks(string $kind): array
    {
        $all = (array) config('unico-core.documents.default_checks', []);

        return $all[$kind] ?? $all['*'] ?? [];
    }

    /** Tipi che si possono riconoscere per questo documento: del tenant o globali, applicabili al suo soggetto, con regex o AI. */
    private function candidates(Document $document): Collection
    {
        return DocumentType::query()
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $document->company_id))
            ->where(fn ($q) => $q->whereNull('document_typable')->orWhere('document_typable', $document->documentable_type))
            ->where(fn ($q) => $q->whereNotNull('regex')->orWhere('is_ai_check', true))
            ->get();
    }

    /** @param array<string,mixed> $attributes */
    private function record(Document $document, string $kind, string $outcome, array $attributes, ?array $usage = null): DocumentAnalysis
    {
        if ($usage) {
            $attributes += [
                'ai_model' => $usage['model'],
                'ai_input_tokens' => $usage['input_tokens'],
                'ai_output_tokens' => $usage['output_tokens'],
                'ai_cost' => AiCost::usd($usage['input_tokens'], $usage['output_tokens']),
            ];
        }

        return DocumentAnalysis::create($attributes + [
            'document_id' => $document->getKey(),
            'kind' => $kind,
            'outcome' => $outcome,
            'created_by' => Auth::id(),
        ]);
    }

    private function worst(string $a, string $b): string
    {
        return self::RANK[$b] > self::RANK[$a] ? $b : $a;
    }
}
