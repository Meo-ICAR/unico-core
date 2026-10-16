<?php

namespace Unico\Core\Signature;

use Unico\Core\Signature\Contracts\SignatureProvider;
use Unico\Core\Enums\SignatureRequestStatus;
use Unico\Core\Enums\SignerStatus;
use Unico\Core\Signature\Dto\EnvelopeData;
use Unico\Core\Signature\Dto\EnvelopeRef;
use Unico\Core\Signature\Dto\EnvelopeSigner;
use Unico\Core\Signature\Dto\EnvelopeStatus;
use Unico\Core\Signature\Dto\SignatureEvent;
use Unico\Core\Signature\Dto\SignerState;
use Unico\Core\Signature\Exceptions\EnvelopeNotFoundException;
use Unico\Core\Signature\Exceptions\InvalidEnvelopeException;
use Unico\Core\Signature\Exceptions\ProviderUnavailableException;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;
use ZipArchive;

/**
 * Adattatore Yousign v3 basato solo sul client HTTP di Laravel.
 *
 * Le ipotesi non confermate dalla documentazione sono marcate "(*) da verificare sul sandbox"
 * e si trovano nelle costanti PATH_CANCEL e PATH_DOWNLOAD, in createEnvelope() (scadenza, ordine
 * firmatari, activate senza corpo), downloadSigned() (header Accept), mapEnvelopeStatus(),
 * signedAt(), cancelPayload(), parseWebhook() e webhookEventTypes().
 * Solo GET e DELETE vengono ritentati: i POST non sono idempotenti e partono una sola volta.
 */
class YousignSignatureProvider implements SignatureProvider
{
    private const PATH_REQUESTS = '/signature_requests';

    private const PATH_DOCUMENTS = '/signature_requests/%s/documents';

    private const PATH_SIGNERS = '/signature_requests/%s/signers';

    private const PATH_ACTIVATE = '/signature_requests/%s/activate';

    private const PATH_CANCEL = '/signature_requests/%s/cancel'; // (*) da verificare sul sandbox

    private const PATH_DOWNLOAD = '/signature_requests/%s/documents/download'; // (*) da verificare sul sandbox

    private const PATH_DOWNLOAD_DOCUMENT = '/signature_requests/%s/documents/%s/download'; // (*) da verificare sul sandbox

    private const SIGNATURE_HEADER = 'X-Yousign-Signature-256';

    /**
     * @param  array{api_key?: ?string, base_url?: ?string, webhook_secret?: ?string, signature_level?: ?string, timeout?: int|string|null, retry_delay_ms?: int|string|null}  $config
     */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'yousign';
    }

    public function requiredContactFields(): array
    {
        return ['email', 'phone'];
    }

    public function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? ''), '/');
    }

    /**
     * Lettura di sola consultazione usata da `signature:check`: ritorna il codice HTTP senza lanciare per 4xx/5xx.
     *
     * @throws ConnectionException
     */
    public function ping(): int
    {
        return $this->client()->get(self::PATH_REQUESTS, ['limit' => 1])->status();
    }

    public function createEnvelope(EnvelopeData $envelope): EnvelopeRef
    {
        $created = $this->send('POST', self::PATH_REQUESTS, [
            'name' => $envelope->name,
            'delivery_mode' => 'email',
            'ordered_signers' => true,
            'timezone' => 'Europe/Rome',
            'external_id' => $envelope->externalId,
            // (*) da verificare sul sandbox: campo e formato della scadenza
            'expiration_date' => $envelope->expiresAt?->format('Y-m-d'),
        ] + $this->emailNotification($envelope->emailSubject));
        $requestId = $this->requireId($created, 'richiesta');

        try {
            $documentIds = [];

            foreach ($envelope->documents() as $envelopeDocument) {
                $document = $this->send('POST', sprintf(self::PATH_DOCUMENTS, $requestId), [
                    'nature' => 'signable_document',
                ], attachment: [$envelopeDocument->pdf, $envelopeDocument->fileName]);
                $documentIds[] = $this->requireId($document, 'documento');
            }

            $signers = collect($envelope->signers)->sortBy('position')->values();
            $signerRefs = [];

            // (*) da verificare sul sandbox: con ordered_signers l'ordine di firma e' l'ordine di creazione
            foreach ($signers as $signer) {
                $response = $this->send('POST', sprintf(self::PATH_SIGNERS, $requestId), $this->signerPayload($signer, $documentIds));
                $signerRefs[$signer->slot] = $this->requireId($response, 'firmatario');
            }

            // (*) da verificare sul sandbox: activate senza corpo JSON
            $this->send('POST', sprintf(self::PATH_ACTIVATE, $requestId), null);
        } catch (Throwable $e) {
            $this->discard($requestId);

            throw $e;
        }

        return new EnvelopeRef($requestId, $signerRefs, $documentIds);
    }

    public function envelopeStatus(string $providerRef): EnvelopeStatus
    {
        $request = $this->send('GET', self::PATH_REQUESTS.'/'.$providerRef);
        $signers = $this->send('GET', sprintf(self::PATH_SIGNERS, $providerRef));

        $states = collect($signers->json() ?? [])
            ->filter(fn ($signer) => is_array($signer) && isset($signer['id']))
            ->map(fn (array $signer) => new SignerState(
                (string) $signer['id'],
                $this->mapSignerStatus((string) ($signer['status'] ?? '')),
                $this->signedAt($signer),
            ))
            ->values()
            ->all();

        return new EnvelopeStatus($this->mapEnvelopeStatus((string) $request->json('status')), $states);
    }

    public function downloadSigned(string $providerRef, ?string $documentRef = null): string
    {
        $path = $documentRef === null ? sprintf(self::PATH_DOWNLOAD, $providerRef) : sprintf(self::PATH_DOWNLOAD_DOCUMENT, $providerRef, $documentRef);

        // (*) da verificare sul sandbox: header Accept per lo scarico
        $response = $this->send('GET', $path, accept: 'application/pdf, application/zip');
        $contentType = strtolower((string) $response->header('Content-Type'));

        if (str_contains($contentType, 'application/pdf')) {
            return $response->body();
        }

        if (str_contains($contentType, 'application/zip')) {
            return $this->firstPdfFromZip($response->body());
        }

        throw new InvalidEnvelopeException('Formato del documento firmato non riconosciuto');
    }

    public function cancelEnvelope(string $providerRef): void
    {
        $this->send('POST', sprintf(self::PATH_CANCEL, $providerRef), $this->cancelPayload());
    }

    public function parseWebhook(Request $request): ?SignatureEvent
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');
        $header = (string) $request->header(self::SIGNATURE_HEADER);

        if ($secret === '' || $header === '') {
            return null;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $header)) {
            return null;
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return null;
        }

        // (*) da verificare sul sandbox: struttura del payload
        $eventName = (string) ($payload['event_name'] ?? '');
        $ref = (string) ($payload['data']['signature_request']['id'] ?? '');
        $eventId = (string) ($payload['event_id'] ?? '');

        $type = in_array($eventName, $this->webhookEventTypes(), true) && $ref !== '' ? $eventName : 'ignored';

        return new SignatureEvent($ref, $type, $eventId);
    }

    /**
     * @return array<int, string>
     */
    private function webhookEventTypes(): array
    {
        // (*) da verificare sul sandbox: nomi degli eventi di rifiuto
        return [
            'signer.done',
            'signature_request.done',
            'signature_request.expired',
            'signature_request.canceled',
            'signer.declined',
            'signature_request.declined',
        ];
    }

    private function mapEnvelopeStatus(string $status): SignatureRequestStatus
    {
        // (*) da verificare sul sandbox: valori esatti dello stato
        return match ($status) {
            'done' => SignatureRequestStatus::Signed,
            'expired' => SignatureRequestStatus::Expired,
            'canceled', 'cancelled' => SignatureRequestStatus::Cancelled,
            'rejected', 'declined' => SignatureRequestStatus::Declined,
            default => SignatureRequestStatus::Sent,
        };
    }

    private function mapSignerStatus(string $status): SignerStatus
    {
        return match ($status) {
            'signed' => SignerStatus::Signed,
            'declined', 'aborted' => SignerStatus::Declined,
            'notified', 'verified', 'initiated' => SignerStatus::Notified,
            default => SignerStatus::Waiting,
        };
    }

    /**
     * @param  array<string, mixed>  $signer
     */
    private function signedAt(array $signer): ?Carbon
    {
        // (*) da verificare sul sandbox: nome del campo con la data di firma
        $value = $signer['signed_at'] ?? null;

        return $value ? Carbon::parse($value)->setTimezone(config('app.timezone') ?: 'UTC') : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function emailNotification(?string $subject): array
    {
        if ($subject === null || $subject === '') {
            return [];
        }

        return ['email_notification' => ['sender' => ['type' => 'organization'], 'custom_text' => [
            'request_subject' => $subject,
            'reminder_subject' => $subject,
        ]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function cancelPayload(): array
    {
        // (*) da verificare sul sandbox: motivi ammessi
        return ['reason' => 'other', 'custom_note' => "Annullata dall'operatore"];
    }

    /**
     * @param  list<string>  $documentIds  id dei documenti della busta, per indice
     * @return array<string, mixed>
     */
    private function signerPayload(EnvelopeSigner $signer, array $documentIds): array
    {
        return [
            'info' => [
                'first_name' => $signer->firstName,
                'last_name' => $signer->lastName,
                'email' => $signer->email,
                'phone_number' => $signer->phone,
                'locale' => 'it',
            ],
            'signature_level' => $this->config['signature_level'] ?? 'electronic_signature',
            'signature_authentication_mode' => 'otp_sms',
            'fields' => array_map(fn ($placement): array => [
                'type' => 'signature',
                'document_id' => $documentIds[$placement->document] ?? $documentIds[0],
                'page' => $placement->page,
                'x' => $placement->x,
                'y' => $placement->y,
                'width' => $placement->width,
                'height' => $placement->height,
            ], $signer->placements()),
        ];
    }

    private function firstPdfFromZip(string $content): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new InvalidEnvelopeException('Estrazione dell\'archivio non disponibile sul server');
        }

        $path = tempnam(sys_get_temp_dir(), 'ysz');
        $zip = new ZipArchive;

        try {
            file_put_contents($path, $content);

            if ($zip->open($path) !== true) {
                throw new InvalidEnvelopeException('Archivio del documento firmato non leggibile');
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (str_ends_with(strtolower($name), '.pdf')) {
                    return (string) $zip->getFromIndex($i);
                }
            }
        } finally {
            $zip->close();
            @unlink($path);
        }

        throw new InvalidEnvelopeException('Nessun PDF nell\'archivio del documento firmato');
    }

    /**
     * Rimozione best effort della richiesta rimasta a meta': gli errori vengono ignorati.
     */
    private function discard(string $requestId): void
    {
        try {
            $this->client()->delete(self::PATH_REQUESTS.'/'.$requestId);
        } catch (Throwable) {
            // best effort
        }
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) ($this->config['api_key'] ?? ''))
            ->acceptJson()
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->baseUrl($this->baseUrl());
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @param  array{0: string, 1: string}|null  $attachment  contenuto e nome file (multipart)
     */
    private function send(string $method, string $path, ?array $data = null, ?array $attachment = null, ?string $accept = null): Response
    {
        $client = $this->client();

        if ($accept !== null) {
            $client = $client->withHeaders(['Accept' => $accept]);
        }

        if ($method !== 'POST') {
            $client = $client->retry(
                2,
                (int) ($this->config['retry_delay_ms'] ?? 500),
                fn (Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()),
                throw: false,
            );
        }

        try {
            if ($attachment !== null) {
                $client = $client->attach('file', $attachment[0], $attachment[1]);
            }

            $response = match ($method) {
                'GET' => $client->get($path),
                'POST' => $data === null && $attachment === null ? $client->send('POST', $path) : $client->post($path, $data ?? []),
                'DELETE' => $client->delete($path),
            };
        } catch (ConnectionException) {
            throw new ProviderUnavailableException('Yousign non raggiungibile');
        }

        return $this->ensureSuccessful($response);
    }

    private function ensureSuccessful(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();

        throw match (true) {
            $status === 401, $status === 403 => new ProviderUnavailableException('Chiave API Yousign non valida'),
            $status === 404 => new EnvelopeNotFoundException('Richiesta Yousign non trovata'),
            $status === 429 => new ProviderUnavailableException('Yousign: troppe richieste, riprovare piu\' tardi'),
            $status >= 400 && $status < 500 => new InvalidEnvelopeException($this->rejectionMessage($response)),
            default => new ProviderUnavailableException("Yousign non disponibile (HTTP {$status})"),
        };
    }

    /**
     * Messaggio generico: il testo libero del provider non viene mai riportato (puo' contenere dati personali).
     * Si aggiungono solo i nomi dei campi non validi, se hanno un formato innocuo.
     */
    private function rejectionMessage(Response $response): string
    {
        $message = "Dati rifiutati dal provider (codice {$response->status()})";

        $names = collect($response->json('invalid_params') ?? [])
            ->map(fn ($param) => is_array($param) ? ($param['name'] ?? null) : null)
            ->filter(fn ($name) => is_string($name) && preg_match('/^[a-z0-9_.\[\]-]{1,60}$/i', $name) === 1)
            ->take(10)
            ->all();

        return $names === [] ? $message : $message.': '.implode(', ', $names);
    }

    private function requireId(Response $response, string $what): string
    {
        $id = $response->json('id');

        if (! is_string($id) || $id === '') {
            throw new InvalidEnvelopeException("Risposta Yousign priva dell'identificativo ({$what})");
        }

        return $id;
    }
}
