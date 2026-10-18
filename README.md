# unico-core

Tabelle condivise della suite Unico: unicofinance (istruttoria; unicoloan è dismesso), proforma, unicooam, unicobpm e, per le funzioni email e Drive, unicogdpr.
Ogni app resta un progetto Laravel separato e mostra le stesse tabelle con le proprie risorse Filament.

unicoagent non usa il core: è un'app separata con il proprio database, che si collega ai CRM (incluse le app Unico) tramite API.

## Contenuto

- `database/migrations/`: 67 tabelle core in 7 aree e 120 chiavi esterne, aggiunte in una migration finale
- `docs/documents-design.md`: schema di documenti e tipi documento e, funzione per funzione, quale implementazione portare da ciascuna app
- `docs/porting-email-drive.md`: piano per portare nel pacchetto la lettura email e la gestione di Google Drive da unicogdpr
- `docs/schema-map.md`: per ogni tabella, da quali app arriva ogni colonna e quali conversioni sono state fatte
- `tools/schema-merge/`: script che rigenerano le migration dai dump e dalle regole in `spec.py`; i commenti di tabelle e colonne sono in `comments.py` (la generazione si ferma se ne manca uno)

## Modelli

- Un modello Eloquent per ognuna delle 67 tabelle, tutti sulla connessione `core` (trait `UsesCoreConnection`). `Document`, `DocumentType`, `DocumentReminder`, `Company`, `User` e `Media` sono scritti a mano; gli altri sono generati dallo schema reale con `tools/models/gen_models.py` (colonne JSON, booleani, date, cast `encrypted` per password e token, relazioni dalle chiavi esterne).
- `Unico\Core\MorphMap`: alias stabili (`company`, `employee`, `cliente`, `fornitore`, `pratica`, ...) per i campi `*_type`, registrati dal provider. Con `UNICO_CORE_ENFORCE_MORPH_MAP=true` Laravel rifiuta i modelli senza alias. Le app aggiungono i propri alias con `Relation::morphMap()`.
- `Media` va indicato in `config/media-library.php` come `media_model`.
- `Unico\Core\Enums\DocumentStatus`: unico elenco ammesso per `documents.status`, con cast sul modello.
- File dei documenti: media collection `documents` (e `signed` per la versione firmata) sul disco privato `documents`, registrato dal pacchetto se l'app non lo definisce. Il download passa da `documents.download` (rotta con `auth`, policy `download`/`view` dell'app o filtro per `company_id`).
- Le scadenze si calcolano dal tipo documento solo se mancano o se cambia la data di emissione: una scadenza inserita a mano non viene mai sovrascritta.
- `Document::renew()` crea il nuovo documento e valorizza `replaced_by_id` sul vecchio.

## Email

- `Unico\Core\Mail\MailFetcher` scarica una casella in `incoming_emails` (finestra di date con sovrapposizione, deduplica per `company_id` + `message_id` anche sui record eliminati, thread da `References`/`In-Reply-To`, allegati nella collection `email_attachments`) e per ogni nuova email emette `Unico\Core\Events\IncomingEmailReceived`. Gli ascoltatori sono delle app: unicogdpr apre richieste e reclami, unicobpm collega gli allegati ai processi, unicofinance alle pratiche. Un ascoltatore che fallisce non ferma la scansione.
- `ImapConnectionFactory`: Basic Auth o OAuth2 (Google, Microsoft) con rinnovo del token tramite Socialite. Solo IMAP: webklex/php-imap non supporta POP3.
- `RuleBasedClassifier`: riconosce da solo mancati recapiti, notifiche dei provider, inviti e spam; le classi dell'app (reclami, richieste GDPR...) si aggiungono in `unico-core.mail.rules`.
- `BounceParser`, `OutgoingMailer` (invio con le credenziali SMTP della casella).
- Comandi: `unico-core:mail-fetch {account?} {--sync}` e `unico-core:mail-health`, che emette `MailAccountsUnhealthy`. La pianificazione resta all'app.

## Google Drive

- `GoogleDriveFactory`: unico punto in cui si leggono le credenziali (`GOOGLE_DRIVE_CREDENTIALS`, default `storage/app/google-credentials.json`). Con `GOOGLE_DRIVE_IMPERSONATE` usa la delega a livello di dominio: i file appartengono a un utente reale e l'errore `storageQuotaExceeded` sparisce. Senza delega si possono scrivere solo Drive condivisi.
- `DriveFolders::ensure($parentId, 'A/B/C')`: trova o crea un percorso. Passa sempre `supportsAllDrives` e `includeItemsFromAllDrives`; in unicogdpr due classi non lo facevano e sui Drive condivisi creavano cartelle duplicate a ogni esecuzione.
- `DriveUploader`, `CompanyFolderProvisioner` (cartella madre = capogruppo, altrimenti `UNICO_CORE_DRIVE_ROOT`; niente ID scritti nel codice), `AuditFolderProvisioner`, `DocumentDriveSync` (usa `documents.sync_status`, `app_id`, `app_drive_id`), `EmailDriveArchiver` (con ripiego sul disco locale), `ZipExporter` (risolve le scorciatoie ed esporta i file Google in formato Office).
- Struttura delle cartelle, gruppi degli audit e nome della cartella email sono in `unico-core.drive`.
- `google/apiclient` pesa centinaia di MB con tutti i servizi: nell'`composer.json` **dell'app** (non del pacchetto) servono `"scripts": {"pre-autoload-dump": "Google\\Task\\Composer::cleanup"}` e `"extra": {"google/apiclient-services": ["Drive"]}`.

## SharePoint

Alternativa a Google Drive (portata da unicooam). I file restano in Spatie Media Library, che è la copia di riferimento; Drive e SharePoint ricevono una copia e aggiornano le stesse colonne di `documents` (`document_url`, `app_id`, `app_drive_id`, `app_etag`, `source_app`, `sync_status`). Un documento sta in un solo cloud alla volta.

- Config `unico-core.sharepoint` (tenant, client, secret, drive, `upload_root`); leggono anche le variabili `SHARE_*` di unicooam.
- `UNICO_CORE_SHAREPOINT_AUTO=true`: ogni file caricato su un `Document` parte in coda (`UploadDocumentToSharePoint`). Senza credenziali il documento resta `local`.
- Sopra i 4 MB si usa la upload session a blocchi; i nomi sono ripuliti dai caratteri non ammessi; gli URL oltre 255 caratteri stanno solo in `metadata.web_url`.
- `php artisan unico-core:sharepoint-retry [--local]` rimette in coda i falliti (e i solo locali).
- Il trasporto verso Graph è sostituibile (`GraphTransport`).

## Analisi documenti

`Unico\Core\Documents\DocumentPipeline` analizza un `Document` in tre passi, ognuno con una riga in `document_analyses` (esito, confidenza, modello AI, token, costo):

1. **verification** (senza AI): file mancante o non apribile, duplicato SHA-256 per soggetto e tipo, scaduto, firma (otp / digitale / olografa / assente).
2. **classification**, se il tipo manca: regex del tipo sul nome file, poi AI sul contenuto (soglia `min_confidence` del tipo). Non forza mai un abbinamento.
3. **extraction**: una sola lettura AI (`document_types.ai_kind`) e controlli a plug-in (`document_types.checks`, altrimenti `documents.default_checks`): `leggibile`, `tipo_documento`, `dati_coerenti`, `non_scaduto`. Le app aggiungono i propri con `CheckRegistry::extend()`.

L'AI non parte su file mancanti, non apribili o duplicati, né se il `AnalysisGate` dell'app lo nega (es. informativa privacy non ancora verificata). La pipeline non respinge mai da sola; approva solo se il tipo ha `allow_auto_verification` e ogni passo è superato. A fine analisi parte `DocumentAnalyzed` con i dati letti da proporre.

Il file si legge sempre da Spatie Media Library (`MediaDocumentFileSource`): Drive e SharePoint sono destinazioni di sincronizzazione, non la sorgente. Punti di aggancio sostituibili con `bind`: `DocumentReader` (Anthropic con `ANTHROPIC_API_KEY`, altrimenti nessuna lettura AI), `DeclaredData` (dati noti con cui confrontare), `AnalysisGate`, `DocumentFileSource`. `unico-core.documents.analysis.auto=true` mette in coda l'analisi a ogni file caricato; oppure `AnalyzeDocument::dispatch($document)`.

## Firma elettronica
`Signature\SignatureRequestService` invia un documento a Yousign (o al provider `fake` in sviluppo), riconcilia lo stato, annulla e chiude le richieste scadute.
- Provider: `SIGNATURE_DRIVER=fake|yousign` (config `unico-core.signature`). Il provider è un'interfaccia (`Signature\Contracts\SignatureProvider`).
- Il modulo PDF del tipo documento (`document_types` → `pdf_modules.signature_slots`) dice dove firma ciascuno; il PDF viene prima reso statico con `Pdf\PdfFormFiller::flattenContent`.
- A firma completata il PDF firmato va nella collection `signed` **dello stesso documento**, che diventa `is_signed`; l'originale resta in `documents`. Non si crea un secondo documento.
  `Document::currentMedia()` restituisce il firmato se c'è, altrimenti l'originale: lo usano il download (`documents.download`), la lettura per l'analisi e la sincronizzazione con Drive e SharePoint.
  A firma completata un documento già `synced` torna `local`, così al giro successivo il cloud riceve il firmato (accanto all'originale, che non viene cancellato).
- Eventi per le app (nessun registro attività o KYC nel pacchetto): `SignatureSent`, `SignatureCancelled`, `SignatureCompleted` (le app vi collegano i loro effetti, es. l'approvazione del QAV).
- Webhook: `POST signature/webhook/{provider}` (middleware `api`, disattivabile con `UNICO_CORE_SIGNATURE_WEBHOOK=false`); accetta solo il provider attivo e mette in coda `ReconcileSignatureRequest`.
- Comandi: `signature:sync` (riconcilia le richieste senza aggiornamenti e chiude le scadute; da schedulare) e `signature:check` (verifica credenziali e raggiungibilità).
- I firmatari proposti (`SignerDefaults`: cliente della pratica, collaboratore) restano nell'app, perché dipendono dalle sue pratiche.

## Scadenziario e solleciti

`DocumentReminderService` (unione di unicooam e unicoloan) decide cosa sollecitare: documenti monitorati in scadenza entro `window_days`, richiesti e non arrivati, respinti, e caricati con anomalie tecniche (ultima riga `verification` fallita in `document_analyses`). Le versioni sostituite (`replaced_by_id`), superate da una più recente o rinnovate da un altro tipo (`renewed_by_id`) restano fuori.

- I solleciti vanno raggruppati per soggetto, una mail per soggetto, una volta per soglia di giorni (`document_types.notify_days_before`, altrimenti `default_notify_days_before`); i documenti senza scadenza si ripetono ogni `missing_reminder_days`. Template email `DOC_EXPIRING` (segnaposto `{agente_nome}`, `{documento_nome}`, `{data_scadenza}`, `{elenco_documenti}`).
- Il destinatario si configura per alias in `documents.reminders.recipients` (campi provati in ordine); senza email vale `fallback_email`. Regole diverse (es. i documenti di una pratica vanno al cliente): legare una propria `RecipientResolver`.
- `ScheduleSynchronizer` riscrive `document_schedules`; `expiryStatus()` e `expiredStatus()` danno conteggio e grado (`ok`, `regular`, `warning`, `alert`) per i controlli periodici di unicobpm.
- Comandi: `unico-core:documents-schedule-sync`, `unico-core:documents-remind [--force] [--sync]`.

## Pratiche: check list, documenti e stati

- **Check list.** I documenti da chiedere per una pratica stanno nei `tasks` con `taskable = pratica` e trigger `tipo_prodotto` = nome del prodotto; i tipi richiesti sono in `document_requirements`.
  - Fase 1, documenti da chiedere al cliente: un task «Check list …» per gruppo di tipologia lavorativa (`trigger_subfield = employment_document_group`) o, per i prodotti aziendali, un task unico.
  - Fase 2, modulistica da stampare e firmare con OTP: il task di stato «… · FASCICOLO COMPLETO».
- **Ereditarietà.** Un task può avere un task padre (`parent_id`): `Task::requisitiEffettivi()` restituisce i documenti del padre più i propri, e un requisito del figlio con lo stesso tipo sostituisce quello del padre. Il mutuo per ristrutturazione eredita così la check list del mutuo e aggiunge solo i preventivi dei lavori. Il prodotto del task si legge da `tasks.tipoprodotto_id` (scope `Task::delProdotto()`, con ripiego sul nome in `trigger_value`).
- **Stati di un documento.** Oltre a richiesto, caricato, provvisorio, scaduto e simili, l'istruttoria assegna OK (`approvato`), Respinto, Anomalo, N/A e Integrativo (`DocumentStatus::istruttoria()`), con `Filament\Actions\DocumentOutcomeAction` nella scheda Documenti. `DocumentStatus::soddisfa()` dice se lo stato soddisfa una richiesta.
- **Età massima.** Si esprime con la durata del tipo (`duration`, `duration_unit`, `is_monitored`): `documents.expires_at` = emissione + durata, e un documento scaduto è l'anomalia `expired` nei controlli tecnici. La visura camerale vale 3 mesi.
- **Documenti numerati.** I documenti multipli sono numerati (Busta paga 1-3, CU 1-2, Dichiarazione dei redditi 1-2) e condividono un `codegroup`: un file si assegna al primo numero libero.
- **Generazione.** `Seeders\ChecklistPerfezionamentoSeeder` le genera e si può rieseguire. I task di stato di chirografario e microcredito copiano quelli del leasing, e il mutuo ristrutturazione copia il mutuo con in più i preventivi dei lavori.
- **Lettura.** `Documents\PracticeTaskDocuments` legge check list e fasi per prodotto, gruppo e banca.
- **Documenti di una banca.** Un tipo con `document_types.clienti_id` vale solo per le pratiche di quell'istituto (`DocumentType::appliesToBank`). `Documents\BankDocumentRequirements` lo collega ai task di uno o più prodotti per la fase scelta, e ricava dai trigger per quali prodotti vale.
- **Flag degli stati** (`pratica_stati`):
  - `is_rejected`, `is_sended`, `is_approved` e `is_erogated` dicono quale data della pratica valorizzare al cambio di stato: `rejected_at`, `sended_at`, `approved_at` ed `erogated_at`;
  - `is_suspended` segna le sospese.
  - Gli storici vanno in `pratica_status_history`.
- **Immobili dei mutui.** Stanno in `pratica_immobili` (`PraticaImmobile`, relazione `Pratica::immobili()`), con l'indirizzo in un `Address` di tipo «Località» e l'LTV calcolato con `ltv()`.
- **Codice della banca.** `pratiches.codice_pratica_banca` è il codice assegnato dalla banca sul suo portale. `clientis.portal_url` è il portale dell'istituto.
- **Credit score.** `Clients\OpenApiCreditScoreService` prepara `GET /IT-creditscore-top/{CF}` (`openapi.creditscore_url`). Per ora la chiamata è solo simulata; con `openapi.creditscore_live` attivo lancia un'eccezione finché la chiamata reale non c'è.

## Test

`composer install && vendor/bin/phpunit`. I test usano un database MySQL/MariaDB vuoto (variabili `CORE_TEST_DB_HOST`, `CORE_TEST_DB_PORT`, `CORE_TEST_DB_DATABASE`, `CORE_TEST_DB_USERNAME`, `CORE_TEST_DB_PASSWORD`; default `unico_core_phpunit`, utente `unico`) e ne ricreano le tabelle a ogni avvio: non puntarli a un database con dati (la suite rifiuta di partire su `unico_core_test`, il database condiviso fra le app).

## Installazione in un'app

In `composer.json` dell'app:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/Meo-ICAR/unico-core" }
],
"require": {
    "meo-icar/unico-core": "dev-main"
}
```

In `config/database.php`, aggiungi una connessione `core` che punta al database condiviso:

```php
'core' => [
    'driver' => 'mysql',
    'host' => env('CORE_DB_HOST', '127.0.0.1'),
    'port' => env('CORE_DB_PORT', '3306'),
    'database' => env('CORE_DB_DATABASE', 'unico_core'),
    'username' => env('CORE_DB_USERNAME', 'root'),
    'password' => env('CORE_DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'strict' => true,
    'engine' => 'InnoDB',
],
```

Crea le tabelle, da una sola app:

```sh
php artisan unico-core:migrate            # crea le tabelle core
php artisan unico-core:migrate --status   # stato
php artisan unico-core:migrate --rollback # annulla l'ultimo batch
```

Le migration core sono registrate nella tabella `migrations_core`, separata dalla tabella `migrations` dell'app.
Non vengono caricate da `php artisan migrate`, quindi nessuna app le esegue per sbaglio.

## Decisioni di schema

- **ID interi ovunque.** Tutte le chiavi primarie sono `bigint unsigned auto_increment`. I campi che erano UUID (`char(36)`) e le relazioni polimorfiche sono convertiti in `bigint`.
  **Eccezione:** `documents.documentable_id`, `document_schedules.documentable_id`, `audits.auditable_id`, `document_requests.requestable_id`, `branches.branchable_id`, `suspicious_activity_reports.reportable_id`, `training_records.trainable_id` e `complaint_registry.complainant_id`/`subject_id` sono `varchar(36)`, perché un'app può collegarli a soggetti letti da un database esterno (Proforma: clienti e fornitori con UUID, pratiche con codice testuale). L'elenco è in `STRING_MORPH_IDS` di `tools/schema-merge/spec.py`; altre colonne, che puntano a clienti e fornitori letti da Proforma (`complaint_registry.agent_id`/`bank_id`, `clienti_oam.clienti_id`, `provvigioni_rules.clienti_id`/`fornitori_id`, `tipoprodotto_sub_constraints.clienti_id`, `blacklist_clienti_fornitori.*`, `suspicious_activity_reports.client_id`, `pratica_clients.pratica_id`, `pratica_status_history.pratica_id`, `tipoprodotto_id`/`tipoprodotto_sub_id` di `document_requirements`, `provvigioni_rules` e `tipoprodotto_sub_constraints`), sono `varchar(36)` **senza chiave esterna** (`EXTERNAL_REFS`): perdono l'integrità referenziale finché quei record non passano sulle tabelle del pacchetto. le migration già generate sono state corrette a mano su quelle quattro colonne, perché i dump di partenza non erano più riproducibili.
- **Una tabella per concetto, con tutti i campi.** Per ogni tabella la prima sorgente elencata in `docs/schema-map.md` decide tipi e vincoli. Le colonne che arrivano solo dalle altre app sono aggiunte come nullable.
- **Tabelle fuse.** `pratiches_statos` in `pratica_stati`; `client_pratiches` in `pratica_clients`; `tipo_prodottos` in `tipoprodotto`.
- **Documenti.** `pratica_requisiti` in `document_types`; `requisito_tipo_finanziamento` e `task_document_types` in `document_requirements`; `pratica_requisiti_operativi` e `pratica_document_requests` in `document_requests`; nuova `document_analyses`. Dettagli in `docs/documents-design.md`.
- **Campi sinonimi uniti.** `companies.piva` in `vat_number`; `fornitoris.company_branch_id` in `branch_id`; in `mail_accounts` le colonne `incoming_*` in `imap_*`.
- **Email.** `mail_accounts` unisce lo schema di unicooam con l'autenticazione OAuth2 di unicogdpr. `incoming_emails` arriva da unicogdpr, con un morph `related` al posto dei collegamenti a DSAR e reclami.
- **Permessi.** Si usa il modello di unicobpm: `employee_type_permissions.resource_id` con FK su `resources`. La colonna testuale `resource` di unicoloan e unicooam è eliminata.
- **Pratiche.** `stato_pratica` e `tipo_prodotto` (testo) sono sostituiti da `pratica_stato_id` e `tipoprodotto_id`. Sono aggiunti `company_id`, `fornitori_id` e `clienti_id` per collegare la pratica a tenant, agente e banca.
- **Default rimossi.** Il tenant fisso `5c044917-…` su `clientis` e `fornitoris`; l'hash di password predefinito su `users.password`.
- **`companies.db_password`** è `text`, per poter usare il cast `encrypted` nel modello.

## Da fare nelle app

1. Convertire i valori già salvati nei campi `*_type` (nome di classe → alias) quando si importano dati dalle app; i database attuali sono di sviluppo.
2. Togliere le tabelle core dalle migration delle singole app e puntare i modelli locali al pacchetto.
3. Riscrivere le 34 viste di proforma: usano `pratiches_statos`, `stato_pratica` e gli ID UUID.
4. Se le app usano lo stesso database: dare a ciascuna nomi propri per le tabelle di framework (sessioni, cache, code, `migrations`). Non usare mai `migrate:fresh` o `db:wipe`, che svuotano l'intero database.
