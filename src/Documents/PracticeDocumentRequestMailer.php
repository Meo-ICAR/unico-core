<?php

namespace Unico\Core\Documents;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Unico\Core\Documents\Reminders\ReminderMail;
use Unico\Core\Enums\MailType;
use Unico\Core\Mail\EmailTemplateResolver;
use Unico\Core\Mail\ThreadId;
use Unico\Core\Models\DocumentRequest;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\Pratica;

/**
 * Chiede al cliente per email i documenti ancora da caricare, con l'agente in copia. L'email parte dal mailer di default dell'app
 * (es. l'account OVH del .env) e porta il codice della pratica nell'oggetto e un Message-Id riconoscibile: la risposta con gli
 * allegati si ricollega alla pratica (`PracticeMailReplyHandler`). Il template (`email_templates`) cambia con il grado:
 * ok < regular < warning < alert, per richiesta e solleciti.
 */
class PracticeDocumentRequestMailer
{
    public function __construct(private EmailTemplateResolver $templates) {}

    /**
     * Oggetto e corpo dell'email, con i segnaposto del template sostituiti.
     *
     * @param  Collection<int, DocumentType>  $types
     * @return array{subject: string, body: string}
     */
    public function compose(Pratica $pratica, Collection $types, string $clientName, ?string $agentName = null, string $severity = 'regular'): array
    {
        $template = $this->templates->resolve((string) config('unico-core.practice_intake.request_template', 'PRATICA_DOCUMENTI_RICHIESTI'), $pratica->company_id, config('unico-core.app_identifier'), MailType::Sollecito, $severity);

        $values = [
            '{codice_pratica}' => (string) $pratica->codice_pratica,
            '{cliente_nome}' => $clientName,
            '{agente_nome}' => (string) $agentName,
            '{elenco_documenti}' => static::documentList($types),
        ];

        $subject = $template?->subject ?: 'Pratica {codice_pratica}: documenti da inviare';
        $body = $template?->body ?: static::defaultBody();

        // Il codice nell'oggetto serve a riconoscere la risposta anche se il programma di posta cambia gli header.
        $subject = strtr($subject, $values);

        if (! str_contains($subject, '['.$pratica->codice_pratica.']')) {
            $subject .= ' ['.$pratica->codice_pratica.']';
        }

        return ['subject' => $subject, 'body' => strtr($body, $values)];
    }

    /**
     * Invia la richiesta e registra una richiesta di documento per ciascun tipo.
     *
     * @param  Collection<int, DocumentType>  $types
     * @return int numero di documenti richiesti
     */
    public function send(Pratica $pratica, Collection $types, string $clientEmail, string $clientName, ?string $agentEmail = null, ?string $agentName = null, ?Authenticatable $by = null, string $severity = 'regular'): int
    {
        $message = $this->compose($pratica, $types, $clientName, $agentName, $severity);
        $mailable = new ReminderMail($message['subject'], $message['body']);
        $thread = ThreadId::forPratica($pratica->getKey());

        $mailable->withSymfonyMessage(function ($symfony) use ($thread): void {
            $symfony->getHeaders()->addIdHeader('Message-ID', trim($thread, '<>'));
        });

        $pending = Mail::to($clientEmail);

        if ($agentEmail !== null && $agentEmail !== '' && strcasecmp($agentEmail, $clientEmail) !== 0) {
            $pending = $pending->cc($agentEmail);
        }

        $pending->send($mailable);

        return $this->record($pratica, $types, $clientEmail, $by);
    }

    /**
     * @param  Collection<int, DocumentType>  $types
     */
    private function record(Pratica $pratica, Collection $types, string $clientEmail, ?Authenticatable $by): int
    {
        foreach ($types as $type) {
            $request = DocumentRequest::query()->firstOrNew([
                'requestable_type' => 'pratica',
                'requestable_id' => (string) $pratica->getKey(),
                'document_type_id' => $type->getKey(),
            ]);

            $request->fill([
                'name' => $type->name,
                'company_id' => $pratica->company_id,
                'requested_by_role' => 'agent',
                'assigned_to_type' => 'client',
                // Il destinatario si ricorda qui: il cliente può non avere ancora un'anagrafica con l'email.
                'notes' => 'destinatario: '.$clientEmail,
                'status' => 'richiesto',
                'share_with_client' => true,
                'is_required' => true,
                'requested_at' => $request->requested_at ?? now(),
                'client_notified_at' => now(),
                'agent_notified_at' => now(),
                'created_by' => $request->created_by ?? $by?->getAuthIdentifier(),
            ])->save();
        }

        return $types->count();
    }

    /** @param Collection<int, DocumentType> $types */
    public static function documentList(Collection $types): string
    {
        return '<ul>'.$types->map(fn (DocumentType $type): string => '<li>'.e($type->name).'</li>')->implode('').'</ul>';
    }

    private static function defaultBody(): string
    {
        return '<p>Gentile {cliente_nome},</p>'
            .'<p>per proseguire con la pratica <strong>{codice_pratica}</strong> ci servono ancora i seguenti documenti:</p>'
            .'{elenco_documenti}'
            .'<p>Rispondi a questa email allegando i documenti (foto nitide o PDF). Lascia il codice pratica nell\'oggetto.</p>'
            .'<p>Grazie,<br>{agente_nome}</p>';
    }
}
