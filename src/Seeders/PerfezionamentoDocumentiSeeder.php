<?php

namespace Unico\Core\Seeders;


use Illuminate\Database\Seeder;
use Unico\Core\Documents\PracticeTaskDocuments;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\EmailTemplate;
use Unico\Core\Models\Task;

/**
 * Documenti del perfezionamento nel database core: l'informativa privacy firmata (task senza trigger,
 * da far scaricare e firmare prima di ogni dato personale) e i documenti d'identità con lettura AI
 * sui task PERFEZIONATA di Cessione, Delega, Prestito e Mutuo. Ripetibile; non tocca ciò che è già impostato.
 */
class PerfezionamentoDocumentiSeeder extends Seeder
{
    private const PRODOTTI = ['Cessione', 'Delega', 'Prestito', 'Mutuo'];

    /** nome tipo documento esistente => profilo di lettura AI */
    private const DOCUMENTI_IDENTIFICATIVI = [
        "Carta d'Identità" => 'identita',
        'Codice fiscale / Tessera sanitaria' => 'codice_fiscale',
        'Tessera Sanitaria / Codice fiscale' => 'codice_fiscale',
        'Codice Fiscale' => 'codice_fiscale',
    ];

    /**
     * Riconoscimento dal nome del file (regex); se non basta decide l'AI fra i documenti attesi (`is_ai_check`).
     * nome tipo documento => regex.
     *
     * @var array<string, string>
     */
    private const RICONOSCIMENTO = [
        PracticeTaskDocuments::PRIVACY_DOCUMENT => '/^(?!.*compass).*(informativa|privacy)/i',
        "Carta d'Identità" => '/carta.*identit|identit|patente|passaporto|\\bci\\b/i',
        'Codice fiscale / Tessera sanitaria' => '/codice.?fiscale|tessera.?sanitaria|\\bcf\\b|\\bts\\b/i',
        'Contratto di finanziamento' => '/contratto/i',
    ];

    /**
     * Email di richiesta dei documenti al cliente, per grado (severity): richiesta, promemoria, ultimo sollecito.
     * severity => [oggetto, corpo]. Il codice pratica nell'oggetto si aggiunge da solo se manca.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const EMAIL_RICHIESTA = [
        'regular' => [
            'Pratica {codice_pratica}: documenti da inviare',
            '<p>Gentile {cliente_nome},</p><p>per proseguire con la pratica <strong>{codice_pratica}</strong> ci servono ancora i seguenti documenti:</p>{elenco_documenti}<p>Rispondi a questa email allegando i documenti (foto nitide o PDF) e lascia il codice pratica nell\'oggetto.</p><p>Grazie,<br>{agente_nome}</p>',
        ],
        'warning' => [
            'Promemoria pratica {codice_pratica}: documenti ancora mancanti',
            '<p>Gentile {cliente_nome},</p><p>per la pratica <strong>{codice_pratica}</strong> non abbiamo ancora ricevuto:</p>{elenco_documenti}<p>Ti chiediamo di rispondere a questa email allegandoli, così possiamo proseguire.</p><p>Grazie,<br>{agente_nome}</p>',
        ],
        'alert' => [
            'Ultimo sollecito pratica {codice_pratica}: documenti necessari',
            '<p>Gentile {cliente_nome},</p><p>senza i documenti seguenti non possiamo portare avanti la pratica <strong>{codice_pratica}</strong>:</p>{elenco_documenti}<p>Rispondi a questa email con gli allegati il prima possibile.</p><p>{agente_nome}</p>',
        ],
    ];

    public function run(): void
    {
        $this->emailRichiestaDocumenti();
        $this->informativa();
        $this->documentiIdentificativi();
        $this->riconoscimento();
    }

    private function emailRichiestaDocumenti(): void
    {
        $code = (string) config('unico-core.practice_intake.request_template');

        foreach (self::EMAIL_RICHIESTA as $severity => [$subject, $body]) {
            EmailTemplate::query()->firstOrCreate(
                ['code' => $code, 'severity' => $severity, 'company_id' => null, 'app_identifier' => null],
                [
                    'name' => 'Richiesta documenti al cliente ('.$severity.')',
                    'subject' => $subject,
                    'body' => $body,
                    'placeholders' => ['{codice_pratica}', '{cliente_nome}', '{agente_nome}', '{elenco_documenti}'],
                    'is_active' => true,
                ],
            );
        }
    }

    private function riconoscimento(): void
    {
        foreach (self::RICONOSCIMENTO as $name => $regex) {
            $type = DocumentType::query()->where('name', $name)->first();

            if ($type === null) {
                continue;
            }

            // L'informativa Compass (altro modulo) non va scambiata con la nostra informativa privacy.
            $replace = $name === PracticeTaskDocuments::PRIVACY_DOCUMENT && $type->regex === '/informativa|privacy/i';

            $type->forceFill([
                'regex' => ($type->regex && ! $replace) ? $type->regex : $regex,
                'is_ai_check' => true,
            ])->save();
        }
    }

    private function informativa(): void
    {
        // `document_url` è il link pubblico (o SharePoint) da cui l'agente scarica il modulo: si imposta a mano.
        $type = DocumentType::query()->firstOrCreate(
            ['name' => PracticeTaskDocuments::PRIVACY_DOCUMENT],
            [
                'description' => 'Informativa sul trattamento dei dati personali da far firmare al cliente con firma olografa.',
                'is_practice' => true,
                'is_client' => true,
                'is_signed' => true,
                'nature' => 'incoming',
                'ai_kind' => 'informativa',
                'checks' => ['leggibile', 'tipo_documento', 'informativa_firmata'],
            ],
        );

        $task = Task::query()->firstOrCreate(
            ['name' => PracticeTaskDocuments::PRIVACY_TASK],
            [
                'description' => 'Informativa privacy da scaricare, far firmare al cliente e caricare prima del perfezionamento.',
                'taskable' => 'pratica',
                'is_active' => true,
            ],
        );

        DocumentRequirement::query()->firstOrCreate(
            ['task_id' => $task->getKey(), 'document_type_id' => $type->getKey()],
            ['is_required' => true, 'sort_order' => 1],
        );
    }

    private function documentiIdentificativi(): void
    {
        foreach (self::DOCUMENTI_IDENTIFICATIVI as $name => $kind) {
            $type = DocumentType::query()->where('name', $name)->first();

            if ($type === null) {
                continue;
            }

            if ($type->ai_kind === null) {
                $type->update(['ai_kind' => $kind]);
            }

            // Identità e codice fiscale stanno nelle check list per prodotto: non servono sui task di stato.
            $perfezionata = Task::query()
                ->where('taskable', 'pratica')
                ->where('trigger_subfield', 'stato_pratica')
                ->where('trigger_subvalue', 'PERFEZIONATA')
                ->pluck('id');

            DocumentRequirement::query()->whereIn('task_id', $perfezionata)->where('document_type_id', $type->getKey())->delete();

        }
    }
}
