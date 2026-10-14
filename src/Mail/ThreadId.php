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

    /**
     * Message-Id di una richiesta di documenti su una pratica: la risposta lo riporta in `In-Reply-To`/`References`.
     * Il suffisso casuale rende unico ogni invio, il numero della pratica resta riconoscibile.
     */
    public static function forPratica(int|string $praticaId): string
    {
        return '<pratica-'.$praticaId.'-'.bin2hex(random_bytes(6)).'@'.self::domain().'>';
    }

    /** L'id della pratica a cui si riferisce un Message-Id o un elenco `References` di una richiesta di documenti, se c'è. */
    public static function praticaFrom(?string $headers): ?string
    {
        if ($headers !== null && preg_match('/<pratica-(\d+)-[0-9a-f]+@[^>\s]+>/', $headers, $m)) {
            return $m[1];
        }

        return null;
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
