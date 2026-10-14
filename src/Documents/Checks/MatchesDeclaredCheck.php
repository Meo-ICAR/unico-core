<?php

namespace Unico\Core\Documents\Checks;

use Unico\Core\Documents\CheckResult;
use Unico\Core\Documents\Contracts\DocumentCheck;
use Unico\Core\Documents\DocumentContext;

/**
 * Codice `dati_coerenti`: i dati letti devono coincidere con quelli già noti (cognome, nome, codice fiscale e,
 * per i documenti d'identità, data di nascita, numero e scadenza). I dati letti che mancano fra i noti
 * vengono proposti per la conferma.
 */
class MatchesDeclaredCheck implements DocumentCheck
{
    /** chiave letta dal documento => chiave nei dati noti */
    private const PROPOSABLE = [
        'surname' => 'cognome',
        'name' => 'nome',
        'fiscal_code' => 'codice_fiscale',
        'birth_date' => 'data_nascita',
        'document_number' => 'documento_numero',
        'expiry_date' => 'documento_scadenza',
    ];

    public function __construct(private FieldComparer $compare = new FieldComparer) {}

    public function inspect(DocumentContext $context): CheckResult
    {
        $read = $context->fields() ?? [];
        $known = $context->declared;

        $issues = [
            ...$this->compare->names('Cognome', $read['surname'] ?? null, $known['cognome'] ?? null),
            ...$this->compare->names('Nome', $read['name'] ?? null, $known['nome'] ?? null),
            ...$this->compare->codes('Codice fiscale', $read['fiscal_code'] ?? null, $known['codice_fiscale'] ?? null),
        ];

        if ($context->kind === 'identita') {
            $issues = [
                ...$issues,
                ...$this->compare->dates('Data di nascita', $read['birth_date'] ?? null, $known['data_nascita'] ?? null),
                ...$this->compare->codes('Numero documento', $read['document_number'] ?? null, $known['documento_numero'] ?? null),
                ...$this->compare->dates('Scadenza', $read['expiry_date'] ?? null, $known['documento_scadenza'] ?? null),
            ];
        }

        if ($issues !== []) {
            return new CheckResult($issues);
        }

        $proposals = [];
        foreach (self::PROPOSABLE as $readKey => $knownKey) {
            if (filled($read[$readKey] ?? null) && blank($known[$knownKey] ?? null)) {
                $proposals[$knownKey] = (string) $read[$readKey];
            }
        }

        return CheckResult::pass($proposals);
    }
}
