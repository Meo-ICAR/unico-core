<?php

namespace Unico\Core\Tests;

use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use Unico\Core\Documents\PracticeDocumentRequestMailer;
use Unico\Core\Documents\PracticeMailReplyHandler;
use Unico\Core\Mail\EmailTemplateResolver;
use Unico\Core\Mail\ThreadId;
use Unico\Core\Models\Client;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\EmailTemplate;
use Unico\Core\Models\Fornitore;
use Unico\Core\Models\IncomingEmail;
use Unico\Core\Models\Pratica;
use Unico\Core\Models\PraticaClient;
use Unico\Core\Pdf\PracticeModuleData;
use Unico\Core\Tests\Support\CoreDatabase;

class PracticeMailRequestTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('email_templates', 'pratiches');
        config(['unico-core.documents.reminders.thread_domain' => 'unicofinance.test', 'unico-core.practice_intake.request_template' => 'PRATICA_DOCUMENTI_RICHIESTI']);
    }

    private function pratica(string $code = 'SIM-261014-000001'): Pratica
    {
        $pratica = Pratica::create(['codice_pratica' => $code, 'upload_at' => now()]);
        $pratica->setRelation('praticaClients', new Collection);

        return $pratica;
    }

    public function test_il_message_id_riporta_la_pratica_e_si_riconosce_nella_risposta(): void
    {
        $id = ThreadId::forPratica(42);

        $this->assertMatchesRegularExpression('/^<pratica-42-[0-9a-f]{12}@unicofinance\.test>$/', $id);
        $this->assertSame('42', ThreadId::praticaFrom('<altro@x.it> '.$id));
        $this->assertNotSame($id, ThreadId::forPratica(42));
        $this->assertNull(ThreadId::praticaFrom('<doc-7@unicofinance.test>'));
        $this->assertNull(ThreadId::praticaFrom(null));
    }

    public function test_il_template_cambia_con_il_grado_e_l_oggetto_porta_sempre_il_codice_pratica(): void
    {
        EmailTemplate::create(['code' => 'PRATICA_DOCUMENTI_RICHIESTI', 'name' => 'Richiesta', 'severity' => 'regular', 'subject' => 'Documenti per {codice_pratica}', 'body' => '<p>{cliente_nome}: {elenco_documenti} ({agente_nome})</p>', 'is_active' => true]);
        EmailTemplate::create(['code' => 'PRATICA_DOCUMENTI_RICHIESTI', 'name' => 'Sollecito', 'severity' => 'warning', 'subject' => 'SOLLECITO {codice_pratica} [SIM-261014-000001]', 'body' => '<p>Sollecito {cliente_nome}</p>', 'is_active' => true]);

        $mailer = new PracticeDocumentRequestMailer(new EmailTemplateResolver);
        $types = new Collection([new DocumentType(['name' => "Carta d'Identità"]), new DocumentType(['name' => 'Codice fiscale'])]);
        $pratica = $this->pratica();

        $first = $mailer->compose($pratica, $types, 'Maria Rossi', 'Gabriele Fabbri');
        $this->assertSame('Documenti per SIM-261014-000001 [SIM-261014-000001]', $first['subject']);
        $this->assertStringContainsString('Maria Rossi', $first['body']);
        $this->assertStringContainsString("<li>Carta d&#039;Identità</li>", $first['body']);
        $this->assertStringContainsString('Gabriele Fabbri', $first['body']);

        $reminder = $mailer->compose($pratica, $types, 'Maria Rossi', null, 'warning');
        $this->assertSame('SOLLECITO SIM-261014-000001 [SIM-261014-000001]', $reminder['subject']);
        $this->assertSame('<p>Sollecito Maria Rossi</p>', $reminder['body']);
    }

    public function test_senza_template_si_usa_il_testo_di_base(): void
    {
        $message = (new PracticeDocumentRequestMailer(new EmailTemplateResolver))
            ->compose($this->pratica(), new Collection([new DocumentType(['name' => 'Busta paga'])]), 'Maria Rossi');

        $this->assertStringContainsString('[SIM-261014-000001]', $message['subject']);
        $this->assertStringContainsString('<li>Busta paga</li>', $message['body']);
        $this->assertStringContainsString('Rispondi a questa email', $message['body']);
    }

    public function test_la_pratica_della_risposta_si_riconosce_dagli_header_o_dal_codice_nell_oggetto(): void
    {
        $pratica = $this->pratica();
        $handler = new PracticeMailReplyHandler(app_intake(), new PracticeModuleData);

        $byHeader = new IncomingEmail(['in_reply_to' => ThreadId::forPratica($pratica->id), 'subject' => 'Re: documenti']);
        $this->assertSame($pratica->id, $handler->pratica($byHeader)?->id);

        $bySubject = new IncomingEmail(['subject' => 'R: Documenti [SIM-261014-000001]']);
        $this->assertSame($pratica->id, $handler->pratica($bySubject)?->id);

        $this->assertNull($handler->pratica(new IncomingEmail(['subject' => 'Offerta [XYZ-1]'])));
    }

    public function test_si_accettano_solo_le_risposte_del_cliente_o_dell_agente(): void
    {
        $pratica = $this->pratica();
        $client = new Client(['name' => 'Rossi', 'email' => 'Maria.Rossi@example.test']);
        $link = new PraticaClient(['role' => 'richiedente']);
        $link->setRelation('client', $client);
        $pratica->setRelation('praticaClients', new Collection([$link]));
        $pratica->setRelation('fornitori', new Fornitore(['name' => 'Agente', 'email' => 'agente@races.it']));
        $handler = new PracticeMailReplyHandler(app_intake(), new PracticeModuleData);

        $this->assertTrue($handler->senderAllowed($pratica, 'maria.rossi@example.test'));
        $this->assertTrue($handler->senderAllowed($pratica, ' AGENTE@races.it '));
        $this->assertFalse($handler->senderAllowed($pratica, 'sconosciuto@spam.test'));

        $request = new \Unico\Core\Models\DocumentRequest(['notes' => 'destinatario: familiare@example.test']);
        $this->assertTrue($handler->senderAllowed($pratica, 'Familiare@example.test', new Collection([$request])));
        $this->assertFalse($handler->senderAllowed($pratica, 'familiare@example.test'));
        $this->assertFalse($handler->senderAllowed($pratica, ''));
    }
}

function app_intake(): \Unico\Core\Documents\PracticeDocumentIntake
{
    return (new \ReflectionClass(\Unico\Core\Documents\PracticeDocumentIntake::class))->newInstanceWithoutConstructor();
}
