<?php

namespace Unico\Core\Documents\Checks;

use Unico\Core\Documents\CheckResult;
use Unico\Core\Documents\Contracts\DocumentCheck;
use Unico\Core\Documents\DocumentContext;

/** Codice `tipo_documento`: ciò che l'AI riconosce deve coincidere con il tipo atteso (`document_types.ai_kind`). */
class KindCheck implements DocumentCheck
{
    private const LABELS = [
        'identita' => 'documento d\'identità',
        'codice_fiscale' => 'tessera del codice fiscale',
        'reddito' => 'documento di reddito',
        'informativa' => 'informativa privacy',
        'busta_paga' => 'busta paga',
        'cu' => 'Certificazione Unica',
        'certificato_stipendio' => 'certificato di stipendio',
        'finanziamento' => 'contratto di finanziamento',
        'questionario_kyc' => 'questionario di adeguata verifica',
        'altro' => 'altro tipo di documento',
    ];

    public function inspect(DocumentContext $context): CheckResult
    {
        $detected = $context->fields()['kind_detected'] ?? null;
        $expected = self::LABELS[$context->kind] ?? $context->label();

        if ($detected === 'altro') {
            return CheckResult::fail("Il file non è riconoscibile come {$expected}: controllare di aver caricato il documento giusto.");
        }

        if ($detected && $detected !== $context->kind) {
            return CheckResult::fail('Il file sembra un '.(self::LABELS[$detected] ?? $detected).", non un {$expected}.");
        }

        return CheckResult::pass();
    }
}
