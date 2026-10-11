<?php

namespace Unico\Core\Mail;

/**
 * Identificativo di conversazione dei solleciti: ogni sollecito parte con `In-Reply-To` e `References` pari a `<doc-{id}@dominio>`,
 * così la risposta (che li riporta in `incoming_emails.in_reply_to` / `references`) si ricollega al documento sollecitato.
 */
final class ThreadId
{
    public static function forDocument(int|string $documentId): string
    {
        return '<doc-'.$documentId.'@'.self::domain().'>';
    }

    /** L'id del documento a cui si riferisce un Message-Id o un elenco `References`, se è un identificativo di sollecito. */
    public static function documentFrom(?string $headers): ?string
    {
        if ($headers !== null && preg_match('/<doc-([^@>\s]+)@[^>\s]+>/', $headers, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function domain(): string
    {
        return (string) (config('unico-core.documents.reminders.thread_domain') ?: parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
    }
}
