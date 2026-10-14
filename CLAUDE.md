# unico-core

Pacchetto Composer `meo-icar/unico-core` (namespace `Unico\Core`): moduli condivisi dalle app Unico di un mediatore creditizio
(unicoagent, unicoloan, proforma, unicooam, unicobpm, unicogdpr). Stack: PHP 8.3, MySQL, Laravel 13, Filament 5, Spatie Media Library.
Le app restano separate e possono avere viste diverse sulle stesse tabelle. Tutti gli ID sono bigint interi. I database sono tutti di sviluppo.
Leggere prima `README.md` e `docs/` (schema-map, documents-design, porting-email-drive): contengono le decisioni già prese.

## Regole che non vanno rotte
- Eccezioni agli ID interi (app che leggono da un database esterno come Proforma): colonne polimorfiche in `STRING_MORPH_IDS` e riferimenti in `EXTERNAL_REFS`
  (`tools/schema-merge/spec.py`) sono `varchar(36)`, senza chiave esterna per i riferimenti. Il generatore non si può rigenerare da zero (mancano i dump): le migration si ritoccano a mano e si aggiorna `spec.py`.
- Una tabella per concetto, con tutti i campi uniti. Ogni tabella e ogni colonna ha un `comment` (lo verifica `SchemaCommentsTest`).
- Le migration sono **generate**: non modificarle a mano. Si cambia `tools/schema-merge/spec.py` (regole) o `comments.py` (commenti) e si rigenera
  con `python3 tools/schema-merge/gen.py <cartella>`, poi si copia il risultato in `database/migrations`. Modelli generati: `tools/models/gen_models.py`
  (a mano restano quelli in `HANDWRITTEN`).
- Le migration girano sulla connessione `core` (`UNICO_CORE_DB_CONNECTION`), tabella `migrations_core`, comando `unico-core:migrate`.
- Relazioni polimorfiche: si salva sempre l'alias stabile di `Unico\Core\MorphMap` (`employee`, `company`...), mai il nome della classe.
- I file dei documenti stanno in Spatie Media Library (disco privato `documents`, collection `documents` e `signed`). Drive e SharePoint sono copie di
  sincronizzazione (stesse colonne `document_url`, `app_id`, `app_drive_id`, `app_etag`, `source_app`, `sync_status`), mai la sorgente.
- Stati documento: solo `DocumentStatus` (valori italiani). Sync: `SyncStatus`. Niente altre stringhe.
- Multi-tenant: `company_id` su `users` e sulle tabelle principali; un'app diventa multicompany filtrando per company o per holding.
- Niente dati personali nei log (solo id e nome della classe dell'eccezione).

## Accesso (piano e ruoli)
`Access\AccessChecker` (+ `PlanType` con TRIAL): piano della company per l'app corrente da `company_modules` (modulo = nome dell'app in maiuscolo senza spazi; risorse in `resources.app_name` = nome dell'app), poi ruoli EmployeeType del profilo (`employee_roles`). Le app chiamano `checkPiano()` che usa il pacchetto. Config `unico-core.access` (`PLAN_TYPE` forza il piano, prova scaduta → BASE, `bypass_roles`, `deny_roles`). Ruoli configurati in unicobpm, organigramma in unicooam.

## Email e solleciti
`email_templates`: `company_id` nullo = tutte le company, `app_identifier` nullo = tutte le app, `type` (`MailType`: sollecito, processo, generale), `severity` (ok, regular, warning, alert). `Mail\EmailTemplateResolver` sceglie il più specifico (company, poi app, poi grado; senza il grado richiesto vale quello inferiore più vicino). `mail_accounts.type` + `company_id` decidono il mittente (`Mail\SenderAccountResolver`); le risposte tornano alla stessa casella e le legge `MailFetcher`. `DocumentReminderService::compose()` prepara il sollecito (`ReminderDraft`: destinatario, grado, template, casella, oggetto e corpo) per mostrarlo e farlo cambiare; `send()` lo invia. Il grado è il peggiore tra scadenza e solleciti già inviati (`Documents\Reminders\Escalation`, soglie in `unico-core.documents.reminders.escalation`).

## Bridge per sistemi esterni
`Bridge\CommandDispatcher` + rotte `/api/commands` (comandi in coda con `command_runs` e esito leggibile), `X-Correlation-Id`, chiave `X-Api-Key`: vedi `docs/bridge-api.md`. Attivo con `UNICO_CORE_BRIDGE`.

## Test
`composer install`, un database MySQL vuoto, poi `vendor/bin/phpunit` (serve `pdftk` per i test dei moduli PDF; si salta se manca). Le variabili possono stare nel `.env` (lette da `tests/bootstrap.php`; mai committare il `.env`): `CORE_TEST_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD`
(default `127.0.0.1:3306`, `unico_core_phpunit`, `unico`/`unico`; `unico_core_test` è il database condiviso e i test non lo toccano). I test sono scritti senza un'app Laravel completa (`tests/Support/CoreDatabase.php`);
rete e servizi esterni sono sostituiti da finti (`FakeImap`, `FakeDrive`, `FakeGraph`, `FakeReader`, `FakeMailManager`, `FakeFiles`).
Non lanciare due `phpunit` insieme sullo stesso database: ognuno ricrea le tabelle. Gli errori di integrazione vanno corretti, non aggirati.

## Fatto
Schema (67 tabelle) e modelli; Document/DocumentType; email (IMAP, OAuth, classificazione, rimbalzi); Google Drive; SharePoint;
pipeline di analisi documenti (`Documents\DocumentPipeline`); scadenziario e solleciti (`Documents\Reminders`);
prima esecuzione dei test con le librerie vere (ora 260 test verdi); `Documents\DocumentPathGenerator` (percorsi leggibili, config `documents.readable_paths`)
e contratto `DocumentGenerator`; `Mail\SenderIdentifier` (+ `SenderMatcher` per le app); moduli PDF `Pdf\PdfFormFiller` (pdftk);
firma elettronica `Signature\*` (Yousign + fake, webhook, `signature:sync`/`signature:check`; il firmato va in `signed` dello stesso documento).

## Da fare (in ordine)
1. Collegare le app una alla volta. **unicooam, unicoloan e unicobpm: fatti e su `main`.** **proforma: fatta sul ramo `core-integration`** (Laravel 13, Filament 5, 28 test su 33 con 4 saltati e 1 fallimento preesistente; provvigioni.pratica_id collega le provvigioni alle pratiche (chiave esterna, valorizzata dall'import; id_pratica conserva il codice dell'API); 24 viste su 34).
   unicoloan 511 test su 516, unicobpm 75 su 77 (i fallimenti rimasti c'erano già prima), con un test di fumo sulle pagine elenco ciascuno.
   unicoloan tiene sul database Proforma `Client`, `ClientMandate`, `Tipoprodotto*`, `PraticaClient`...; gli id che li richiamano sono `varchar(36)` senza FK.
   unicobpm usa il database condiviso (connessione `core`, in sviluppo `unicooam_core`) per le tabelle del pacchetto e un database proprio per i processi;
   non esegue le migration del pacchetto e i suoi seeder non vanno lanciati sul database condiviso. Restano nelle app: sostituire le versioni locali con
   quelle del pacchetto (`DocumentReminderService`, job e listener SharePoint; in unicoloan anche `Services\Signature`, `PdfFormFiller`, `DocumentVerifier`, con
   il problema del firmato: nel pacchetto resta nel documento, in unicoloan sostituisce il documento). Poi unicogdpr e proforma (riscrivere le 34 viste di
   proforma, allineare proforma a Laravel 13 e Filament 5).
2. Correzioni nelle app: documenti su disco pubblico, `db_password` in chiaro (enum di stato: fatto in unicooam).
3. unicoagent resta separata e parla via API (`documents.channel = 'whatsapp'`).

## Commit
I messaggi finiscono con le righe di attribuzione richieste dall'ambiente in uso.
