<?php

namespace Unico\Core\Documents\Checks;

use Illuminate\Support\Carbon;
use Unico\Core\Documents\CheckResult;
use Unico\Core\Documents\Contracts\DocumentCheck;
use Unico\Core\Documents\DocumentContext;

/** Codice `non_scaduto`: la scadenza stampata sul documento non deve essere passata (parametro `today` per le prove). */
class NotExpiredCheck implements DocumentCheck
{
    public function __construct(private FieldComparer $compare = new FieldComparer) {}

    public function inspect(DocumentContext $context): CheckResult
    {
        $raw = $context->fields()['expiry_date'] ?? null;
        $date = $this->compare->parseDate($raw);

        if ($date === null) {
            return CheckResult::pass();
        }

        $today = isset($context->params['today']) ? Carbon::parse($context->params['today'])->startOfDay() : Carbon::today();

        return $date->lt($today)
            ? CheckResult::fail('Il documento risulta scaduto (scadenza '.$date->format('d/m/Y').'): serve un documento in corso di validità.')
            : CheckResult::pass();
    }
}
