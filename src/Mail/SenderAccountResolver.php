<?php

namespace Unico\Core\Mail;

use Unico\Core\Enums\MailType;
use Unico\Core\Models\MailAccount;

/**
 * La casella da cui parte un'email è decisa da company e tipo. Si prende la più specifica che può inviare (SMTP configurato):
 *  1. della company, di quel tipo;  2. della company, generale;  3. senza company (comune), di quel tipo;  4. senza company, generale.
 */
class SenderAccountResolver
{
    public function resolve(int|string|null $companyId, MailType|string $type): ?MailAccount
    {
        $type = $type instanceof MailType ? $type->value : $type;
        $general = MailType::Generale->value;
        $attempts = [[$companyId, $type], [$companyId, $general], [null, $type], [null, $general]];

        foreach (array_unique(array_filter($attempts, fn (array $a) => $companyId !== null || $a[0] === null), SORT_REGULAR) as [$company, $wanted]) {
            $account = MailAccount::query()->active()
                ->where('type', $wanted)
                ->when($company === null, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', $company))
                ->orderBy('id')
                ->get()
                ->first(fn (MailAccount $a) => $a->hasSmtpConfigured());

            if ($account !== null) {
                return $account;
            }
        }

        return null;
    }
}
