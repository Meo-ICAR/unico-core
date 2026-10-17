<?php

namespace Unico\Core\Documents;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;
use Unico\Core\Events\IncomingEmailReceived;
use Unico\Core\Mail\ThreadId;
use Unico\Core\Models\DocumentRequest;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\IncomingEmail;
use Unico\Core\Models\Pratica;
use Unico\Core\Pdf\PracticeModuleData;

/**
 * Risposta del cliente a una richiesta di documenti: si riconosce la pratica (header `In-Reply-To`/`References`, oppure il codice
 * pratica fra parentesi quadre nell'oggetto), si accetta solo il mittente atteso (cliente o agente) e gli allegati passano
 * dall'acquisizione dei documenti. Le richieste evase passano a «caricato».
 */
class PracticeMailReplyHandler
{
    public const CLASSIFICATION = 'documenti_pratica';

    public function __construct(private PracticeDocumentIntake $intake, private PracticeModuleData $moduleData) {}

    public function handle(IncomingEmailReceived $event): void
    {
        if (! config('unico-core.practice_intake.enabled', true) || ! config('unico-core.practice_intake.mail_replies', true)) {
            return;
        }

        $this->process($event->email);
    }

    /** @return list<IntakeResult> */
    public function process(IncomingEmail $email): array
    {
        $pratica = $this->pratica($email);

        if ($pratica === null) {
            return [];
        }

        $requests = DocumentRequest::query()
            ->where('requestable_type', 'pratica')
            ->where('requestable_id', (string) $pratica->getKey())
            ->where('status', 'richiesto')
            ->get();

        if (! $this->senderAllowed($pratica, (string) $email->from_email, $requests)) {
            Log::warning('Risposta ignorata: mittente non atteso', ['incoming_email' => $email->getKey(), 'pratica' => $pratica->getKey()]);

            return [];
        }

        $expected = DocumentType::query()->whereIn('id', $requests->pluck('document_type_id'))->get();

        if ($expected->isEmpty()) {
            return [];
        }

        $results = [];

        foreach ($email->getMedia(IncomingEmail::ATTACHMENTS) as $media) {
            $file = $this->uploadedFile($media);

            if ($file !== null) {
                array_push($results, ...$this->intake->intakeAll($pratica, $file, $expected));
            }
        }

        $this->closeRequests($requests, $results);
        $email->forceFill(['related_type' => 'pratica', 'related_id' => $pratica->getKey(), 'classification' => self::CLASSIFICATION])->saveQuietly();

        return $results;
    }

    /** La pratica a cui risponde l'email: prima gli header, poi il codice nell'oggetto. */
    public function pratica(IncomingEmail $email): ?Pratica
    {
        $id = ThreadId::praticaFrom(trim((string) $email->in_reply_to.' '.(string) $email->references));

        if ($id !== null) {
            return Pratica::query()->find($id);
        }

        if (preg_match_all('/\[([^\]\s]+)\]/', (string) $email->subject, $matches)) {
            foreach ($matches[1] as $code) {
                $pratica = Pratica::query()->where('codice_pratica', $code)->first();

                if ($pratica !== null) {
                    return $pratica;
                }
            }
        }

        return null;
    }

    /**
     * Il cliente della pratica, l'agente che l'ha aperta o il destinatario a cui è stata inviata la richiesta.
     *
     * @param  Collection<int, DocumentRequest>|null  $requests  richieste aperte della pratica
     */
    public function senderAllowed(Pratica $pratica, string $from, ?Collection $requests = null): bool
    {
        $from = mb_strtolower(trim($from));

        if ($from === '') {
            return false;
        }

        $recipients = ($requests ?? collect())
            ->map(fn (DocumentRequest $request): ?string => preg_match('/destinatario:\s*(\S+)/', (string) $request->notes, $m) === 1 ? $m[1] : null)
            ->all();

        $allowed = array_filter([
            $this->moduleData->findClient($pratica)?->email,
            $pratica->fornitori?->email,
            ...$recipients,
        ]);

        return in_array($from, array_map(fn (string $email): string => mb_strtolower(trim($email)), $allowed), true);
    }

    /**
     * @param  Collection<int, DocumentRequest>  $requests
     * @param  list<IntakeResult>  $results
     */
    private function closeRequests(Collection $requests, array $results): void
    {
        $received = collect($results)->filter(fn (IntakeResult $result): bool => $result->matched())->map(fn (IntakeResult $result): int => (int) $result->type->getKey())->all();

        $requests->filter(fn (DocumentRequest $request): bool => in_array((int) $request->document_type_id, $received, true))
            ->each(fn (DocumentRequest $request) => $request->forceFill(['status' => 'caricato', 'received_at' => now()])->save());
    }

    private function uploadedFile(Media $media): ?UploadedFile
    {
        try {
            $path = tempnam(sys_get_temp_dir(), 'mail-att-');
            $source = $media->stream();
            $target = fopen($path, 'wb');
            stream_copy_to_stream($source, $target);
            fclose($target);

            return new UploadedFile($path, $media->file_name, $media->mime_type, null, true);
        } catch (Throwable $e) {
            Log::warning('Allegato della risposta non leggibile', ['media' => $media->getKey(), 'exception' => $e::class]);

            return null;
        }
    }
}
