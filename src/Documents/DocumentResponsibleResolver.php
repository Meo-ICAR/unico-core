<?php

namespace Unico\Core\Documents;

use Illuminate\Database\Eloquent\Model;
use Unico\Core\Documents\Reminders\DocumentRequestRouter;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Models\Client;
use Unico\Core\Models\Document;
use Unico\Core\Models\Pratica;

/**
 * Chi deve far proseguire l'iter del documento, in base alla fase (`documents.status`):
 * - da compilare, stampare, scaricare, da inviare per la firma, caricato (da verificare) e firmato: l'istruttore della pratica;
 * - inviato per la firma OTP: chi firma, il richiedente;
 * - da approvare: chi approva secondo `document_types.approver_model` (produttore o istruttore);
 * - richiesto, respinto, anomalo, integrativo, illeggibile, scaduto, provvisorio: chi emette il documento (`document_types.issuer_model`):
 *   il richiedente, ma se la pratica non abilita le richieste dirette (`pratiches.allows_direct_requests`, altrimenti quello del
 *   produttore) il produttore; `employer` il datore di lavoro (solo cessione e delega); `institute` la banca della pratica;
 * - approvato, N/A, revocato: nessuno.
 */
class DocumentResponsibleResolver
{
    public function __construct(private DocumentRequestRouter $router) {}

    public function for(Document $document): ?Model
    {
        $status = $document->status;
        $pratica = $this->router->practiceOf($document->documentable);

        return match ($status) {
            DocumentStatus::MISSING_DATA, DocumentStatus::PRINT, DocumentStatus::DOWNLOAD, DocumentStatus::TO_SEND_OTP,
            DocumentStatus::UPLOADED, DocumentStatus::SIGNED_OTP => $pratica?->employee,
            DocumentStatus::SENT_OTP => $this->requester($document, $pratica),
            DocumentStatus::TO_APPROVE_OTP => $this->approver($document, $pratica),
            DocumentStatus::PENDING, DocumentStatus::REJECTED, DocumentStatus::ANOMALY, DocumentStatus::SUPPLEMENTARY,
            DocumentStatus::UNREADABLE, DocumentStatus::EXPIRED, DocumentStatus::PROVISIONAL => $this->issuer($document, $pratica),
            default => null,
        };
    }

    private function issuer(Document $document, ?Pratica $pratica): ?Model
    {
        $requester = $this->requester($document, $pratica);

        if ($pratica === null) {
            return $requester;
        }

        $producer = $pratica->fornitori;

        return match ($document->documentType?->issuer_model) {
            'institute' => $pratica->clienti ?? $producer,
            'employer' => $this->router->involvesEmployer($pratica) && $requester?->employer !== null ? $requester->employer : $requester,
            default => $producer !== null && ! ($pratica->allows_direct_requests ?? $producer->allows_direct_requests) ? $producer : $requester,
        };
    }

    private function approver(Document $document, ?Pratica $pratica): ?Model
    {
        return match ($document->documentType?->approver_model) {
            'employee' => $pratica?->employee,
            default => $pratica?->fornitori ?? $pratica?->employee,
        };
    }

    private function requester(Document $document, ?Pratica $pratica): ?Model
    {
        $owner = $document->documentable;

        if ($owner instanceof Client) {
            return $owner;
        }

        return $pratica === null ? $owner : $this->router->requesterOf($owner, $pratica);
    }
}
