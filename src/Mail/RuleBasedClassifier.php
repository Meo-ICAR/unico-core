<?php

namespace Unico\Core\Mail;

use Unico\Core\Mail\Contracts\EmailClassifier;
use Unico\Core\Models\IncomingEmail;

/**
 * Classificatore a regole. Riconosce da solo i mancati recapiti, le notifiche dei provider
 * di posta, gli inviti a riunioni e lo spam; le app aggiungono le proprie classi
 * (reclami, richieste dell'interessato...) in `unico-core.mail.rules`:
 * `['etichetta' => ['/regex/iu', ...]]`. L'ordine è significativo: le più specifiche prima.
 */
class RuleBasedClassifier implements EmailClassifier
{
    public const BOUNCE = 'bounce';

    public const PROVIDER_NOTIFICATION = 'provider_notification';

    public const MEETING_INVITE = 'meeting_invite';

    public const SPAM = 'spam';

    public const OTHER = 'other';

    public function classify(IncomingEmail $email): ?string
    {
        $subject = @iconv_mime_decode((string) $email->subject, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: (string) $email->subject;
        $haystack = trim($subject.' '.strip_tags($email->body_text ?: $email->body_html ?? ''));

        if ($haystack === '') {
            return self::OTHER;
        }

        if ($this->looksLikeBounce($email, $haystack)) {
            return self::BOUNCE;
        }

        if ($this->looksLikeProviderNotification($email, $haystack)) {
            return self::PROVIDER_NOTIFICATION;
        }

        if (preg_match('/^(invitation|invito):/i', trim((string) $email->subject))) {
            return self::MEETING_INVITE;
        }

        foreach ((array) config('unico-core.mail.rules', []) as $label => $patterns) {
            foreach ((array) $patterns as $pattern) {
                if (preg_match($pattern, $haystack)) {
                    return (string) $label;
                }
            }
        }

        if (preg_match('/\b(viagra|casino|prize winner|bitcoin doubl\w+|free crypto)\b/i', $haystack)) {
            return self::SPAM;
        }

        return self::OTHER;
    }

    private function looksLikeBounce(IncomingEmail $email, string $haystack): bool
    {
        // Un postmaster scrive anche avvisi di servizio: è un mancato recapito solo se il testo lo dice.
        return str_contains(strtolower((string) $email->from_email), 'mailer-daemon')
            || (bool) preg_match(
                '/\b(delivery status notification|delivery has failed|mail delivery failed|undeliver\w*|returned mail|failure notice|mancato recapito|non recapitat\w*|impossibile recapitare)\b/iu',
                $haystack,
            );
    }

    /** Avvisi automatici del provider di posta stesso: non sono corrispondenza sostanziale. */
    private function looksLikeProviderNotification(IncomingEmail $email, string $haystack): bool
    {
        $from = strtolower((string) $email->from_email);

        if (preg_match('/@(?:[a-z0-9-]+\.)*(?:aruba\.it|aruba\.com|arubapec\.it|google\.com|googlemail\.com|microsoft\.com|microsoftonline\.com|office365\.com|ovh\.(?:com|net|it)|register\.it|godaddy\.com|ionos\.(?:com|it)|zoho\.com|hostinger\.com|apple\.com)$/', $from)) {
            return true;
        }

        $local = strstr($from, '@', true) ?: $from;
        $isAutomatedSender = (bool) preg_match('/^(?:postmaster|no-?reply|do-?not-?reply|notifications?|notifiche|mailer|comunicazioni)\b/', $local);

        return $isAutomatedSender && (
            $local === 'postmaster'
            || preg_match('/\b(?:configurazione della casella|casella di posta|webmail|spazio della casella|quota della casella|rinnovo del servizio|scadenza del servizio|avviso di sicurezza|verifica in due passaggi|mailbox)\b/iu', $haystack)
        );
    }
}
