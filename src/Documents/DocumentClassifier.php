<?php

namespace Unico\Core\Documents;

use Illuminate\Support\Collection;
use Unico\Core\Documents\Contracts\DocumentReader;
use Unico\Core\Models\DocumentType;

/**
 * Capisce a quale tipo fra i candidati corrisponde un file, in due stadi (porta di DocumentClassifier di unicobpm):
 * prima la regex del tipo sul nome file, poi — solo se non risolutiva — l'AI che legge il contenuto.
 * Non forza mai un abbinamento: se nessuno stadio è abbastanza sicuro non sceglie, e decide una persona.
 */
class DocumentClassifier
{
    public function __construct(private DocumentReader $reader) {}

    /** @param Collection<int, DocumentType> $candidates */
    public function classify(Collection $candidates, DocumentFile $file): Classification
    {
        if ($type = $this->byRegex($candidates, $file->name)) {
            return new Classification($type, 100, null, 'procedural', 'regex');
        }

        return $this->byAi($candidates, $file);
    }

    /** @param Collection<int, DocumentType> $candidates */
    private function byRegex(Collection $candidates, string $filename): ?DocumentType
    {
        $matches = $candidates->filter(function (DocumentType $type) use ($filename): bool {
            $pattern = $type->regex;

            if (blank($pattern)) {
                return false;
            }

            // Senza delimitatori il pattern è inteso come espressione libera, senza distinzione di maiuscole.
            if (! preg_match('/^([\/~#@%!]).+\1[imsxuADSUXJ]*$/s', $pattern)) {
                $pattern = '~'.str_replace('~', '\~', $pattern).'~i';
            }

            return @preg_match($pattern, $filename) === 1;
        });

        // Più candidati sullo stesso file: la regex non è risolutiva.
        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @param Collection<int, DocumentType> $candidates */
    private function byAi(Collection $candidates, DocumentFile $file): Classification
    {
        $aiCandidates = $candidates->filter(fn (DocumentType $t) => (bool) $t->is_ai_check);

        if (! $this->reader->enabled() || $aiCandidates->isEmpty()) {
            return new Classification(null);
        }

        $answer = $this->reader->classify($aiCandidates, $file->mime, $file->bytes);
        $usage = $this->reader->lastUsage();
        $type = $answer && $answer['type_id'] ? $aiCandidates->firstWhere('id', $answer['type_id']) : null;

        if (! $type || $answer['confidence'] < ($type->min_confidence ?? 70)) {
            return new Classification(null, $answer['confidence'] ?? null, $answer['abstract'] ?? null, 'ai', 'ai.classifier', $usage);
        }

        return new Classification($type, $answer['confidence'], $answer['abstract'] ?? null, 'ai', 'ai.classifier', $usage);
    }
}
