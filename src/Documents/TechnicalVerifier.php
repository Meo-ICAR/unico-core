<?php

namespace Unico\Core\Documents;

use Unico\Core\Enums\DocumentAnomaly;
use Unico\Core\Models\Document;
use Unico\Core\Enums\SignatureRequestStatus;
use Unico\Core\Models\SignatureRequest;

/**
 * Controlli tecnici senza AI, al caricamento di un documento (porta di DocumentVerifier di unicoloan):
 * file mancante o non apribile, duplicato (SHA-256), scaduto, firma. Segnala, non decide:
 * accettare o respingere resta all'operatore.
 */
class TechnicalVerifier
{
    public const SIGNATURE_NOT_REQUIRED = 'non_richiesta';

    public const SIGNATURE_OTP = 'otp';

    public const SIGNATURE_DIGITAL = 'digitale';

    public const SIGNATURE_HANDWRITTEN = 'olografa';

    public const SIGNATURE_OTP_PENDING = 'otp_in_attesa';

    public const SIGNATURE_MISSING = 'assente';

    private const CLOSED_REQUEST = ['signed', 'declined', 'rejected', 'expired', 'canceled', 'cancelled', 'failed'];

    /**
     * Imposta `file_hash` sul documento (senza salvarlo).
     *
     * @return array{anomalies: list<string>, signature: string, outcome: string, hash: string|null, signers: list<array<string,mixed>>}
     */
    public function check(Document $document, ?DocumentFile $file): array
    {
        $anomalies = [];
        $hash = null;
        $usable = false;

        if ($file === null) {
            if (blank($document->resolved_url)) {
                $anomalies[] = DocumentAnomaly::MissingFile;
            }
        } elseif (! $this->readable($file)) {
            $anomalies[] = DocumentAnomaly::Unreadable;
        } else {
            $usable = true;
            $hash = hash('sha256', $file->bytes);
            $document->file_hash = $hash;

            if ($this->isDuplicate($document, $hash)) {
                $anomalies[] = DocumentAnomaly::Duplicate;
            }
        }

        if ($document->expires_at?->isPast()) {
            $anomalies[] = DocumentAnomaly::Expired;
        }

        $request = SignatureRequest::query()->where('document_id', $document->getKey())->latest('id')->first();
        $signature = $this->signature($document, $request, $usable ? $file : null);

        if (in_array($signature, [self::SIGNATURE_MISSING, self::SIGNATURE_OTP_PENDING], true)) {
            $anomalies[] = DocumentAnomaly::SignatureMissing;
        }

        $signers = $request ? $request->signatureRequestSigners()->get()->map(fn ($s) => [
            'name' => $s->name, 'role' => $s->role?->value, 'status' => $s->status?->value, 'signed_at' => $s->signed_at?->toIso8601String(),
        ])->all() : [];

        if ($signature === self::SIGNATURE_OTP && collect($signers)->contains(fn (array $s) => $s['status'] !== 'signed')) {
            $anomalies[] = DocumentAnomaly::SignatureInvalid;
        }

        $outcome = match (true) {
            $anomalies !== [] => 'failed',
            in_array($signature, [self::SIGNATURE_HANDWRITTEN, self::SIGNATURE_DIGITAL], true) => 'needs_review',
            default => 'passed',
        };

        return [
            'anomalies' => array_map(fn (DocumentAnomaly $a) => $a->value, $anomalies),
            'signature' => $signature,
            'outcome' => $outcome,
            'hash' => $hash,
            'signers' => $signers,
        ];
    }

    /** Firma: otp / digitale (presente, non validata) / olografa (da verificare a vista) / assente / non richiesta. */
    private function signature(Document $document, ?SignatureRequest $request, ?DocumentFile $file): string
    {
        if (! $document->documentType?->is_signed) {
            return self::SIGNATURE_NOT_REQUIRED;
        }

        if ($request?->status === SignatureRequestStatus::Signed || ($request === null && $document->is_signed && $document->signed_at !== null)) {
            return self::SIGNATURE_OTP;
        }

        if ($request !== null && ! in_array($request->status->value, self::CLOSED_REQUEST, true)) {
            return self::SIGNATURE_OTP_PENDING;
        }

        if ($file === null) {
            return self::SIGNATURE_MISSING;
        }

        if (str_contains($file->bytes, '/ByteRange') && str_contains($file->bytes, '/Sig')) {
            return self::SIGNATURE_DIGITAL;
        }

        return self::SIGNATURE_HANDWRITTEN;
    }

    /** Il file esiste, non è vuoto e (per i PDF) comincia con l'intestazione %PDF. */
    private function readable(DocumentFile $file): bool
    {
        if ($file->bytes === '') {
            return false;
        }

        return ! $file->isPdf() || str_starts_with($file->bytes, '%PDF');
    }

    private function isDuplicate(Document $document, string $hash): bool
    {
        return Document::query()
            ->where('documentable_type', $document->documentable_type)
            ->where('documentable_id', $document->documentable_id)
            ->where('document_type_id', $document->document_type_id)
            ->where('file_hash', $hash)
            ->whereKeyNot($document->getKey())
            ->exists();
    }
}
