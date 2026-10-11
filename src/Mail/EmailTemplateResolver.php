<?php

namespace Unico\Core\Mail;

use Unico\Core\Enums\MailType;
use Unico\Core\Models\EmailTemplate;

/**
 * Sceglie il template per un invio. Tra i template attivi con quel codice (e del tipo richiesto, o senza tipo) valgono solo quelli
 * che riguardano la company (o tutte) e l'app (o tutte). A parità di codice vince il più specifico: prima la company, poi l'app, poi
 * la severity. Con la severity richiesta si prende il template di quel grado; se manca, quello del grado inferiore più vicino
 * (ok < regular < warning < alert); se non c'è nemmeno quello, il template senza severity.
 */
class EmailTemplateResolver
{
    public const SEVERITIES = ['ok', 'regular', 'warning', 'alert'];

    public function resolve(string $code, int|string|null $companyId = null, ?string $app = null, MailType|string|null $type = null, ?string $severity = null): ?EmailTemplate
    {
        $type = $type instanceof MailType ? $type->value : $type;

        $candidates = EmailTemplate::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->when($type !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('type')->orWhere('type', $type)))
            ->where(fn ($q) => $q->whereNull('company_id')->when($companyId !== null, fn ($w) => $w->orWhere('company_id', $companyId)))
            ->where(fn ($q) => $q->whereNull('app_identifier')->when($app !== null, fn ($w) => $w->orWhere('app_identifier', $app)))
            ->get();

        $ranked = $candidates
            ->filter(fn (EmailTemplate $t) => $this->severityRank($t->severity, $severity) !== null)
            ->sortByDesc(fn (EmailTemplate $t) => [
                (int) ($t->company_id !== null),
                (int) ($t->app_identifier !== null),
                $this->severityRank($t->severity, $severity),
            ]);

        return $ranked->first();
    }

    /** Più alto = più adatto; nullo = non utilizzabile. Senza severity richiesta valgono solo i template senza severity. */
    private function severityRank(?string $templateSeverity, ?string $wanted): ?int
    {
        if ($wanted === null) {
            return $templateSeverity === null ? 1 : null;
        }

        if ($templateSeverity === null) {
            return 0;
        }

        $have = array_search($templateSeverity, self::SEVERITIES, true);
        $want = array_search($wanted, self::SEVERITIES, true);

        if ($have === false || $want === false || $have > $want) {
            return null;
        }

        // Esatto = grado più alto; più è lontano verso il basso, meno è adatto.
        return 1 + $have;
    }
}
