<?php

namespace Unico\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Alias stabili salvati nei campi `*_type` delle relazioni polimorfiche.
 *
 * Ogni app salvava prima il nome della propria classe (`App\Models\Employee`),
 * quindi lo stesso record aveva valori diversi a seconda dell'app. Nel database
 * condiviso si salva sempre l'alias. Le app possono aggiungere i propri alias
 * (es. unicogdpr: `external_processor`) con `Relation::morphMap([...])`.
 */
final class MorphMap
{
    /** @return array<string, class-string> */
    public static function all(): array
    {
        return [
            'user' => Models\User::class,
            'company' => Models\Company::class,
            'branch' => Models\Branch::class,
            'employee' => Models\Employee::class,
            'client' => Models\Client::class,
            'cliente' => Models\Cliente::class,
            // Grafia già presente sui documenti. Resta dopo `cliente`: i record nuovi continuano a salvarsi con quello.
            'clienti' => Models\Cliente::class,
            'fornitore' => Models\Fornitore::class,
            'organization' => Models\Organization::class,
            'pratica' => Models\Pratica::class,
            'document' => Models\Document::class,
            'document_type' => Models\DocumentType::class,
            'document_request' => Models\DocumentRequest::class,
            'signature_request' => Models\SignatureRequest::class,
            'task' => Models\Task::class,
            'address' => Models\Address::class,
            'mail_account' => Models\MailAccount::class,
            'incoming_email' => Models\IncomingEmail::class,
            'audit' => Models\Audit::class,
            'audit_checklist_evaluation' => Models\AuditChecklistEvaluation::class,
            'audit_finding' => Models\AuditFinding::class,
            'quality_review' => Models\QualityReview::class,
            'complaint' => Models\Complaint::class,
            'complaint_registry' => Models\ComplaintRegistry::class,
            'suspicious_activity_report' => Models\SuspiciousActivityReport::class,
            'training_record' => Models\TrainingRecord::class,
            'tipoprodotto' => Models\TipoProdotto::class,
            'tipoprodotto_sub' => Models\TipoProdottoSub::class,
            'website' => Models\Website::class,
        ];
    }

    /**
     * L'alias di un modello anche quando è una sottoclasse di quella registrata (es. `App\Models\Pratica` che estende la classe
     * del core): `Relation::getMorphAlias()` conosce solo la classe esatta.
     */
    public static function aliasOf(Model $record): string
    {
        $alias = Relation::getMorphAlias($record::class);

        if ($alias !== $record::class) {
            return $alias;
        }

        foreach (array_merge(self::all(), Relation::morphMap()) as $name => $class) {
            if ($record instanceof $class) {
                return $name;
            }
        }

        return $record->getMorphClass();
    }
}
