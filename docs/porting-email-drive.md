# Porting di email e Google Drive da unicogdpr

unicogdpr resta un'app separata. Le funzioni di lettura email e di gestione Google Drive vanno estratte nel pacchetto, così unicobpm, unicofinance e unicooam le usano senza duplicarle. Le parti specifiche del GDPR (DSAR, classificazione GDPR, data breach) restano in unicogdpr, che userà il pacchetto come le altre app.

Codice di partenza (repo `Meo-ICAR/unicogdpr`): `app/Services/Mail`, `app/Services/Drive`, `app/Jobs/FetchMailAccountJob.php`, `app/Contracts/ImapConnector.php`, `app/Models/{MailAccount,IncomingEmail}.php`, `app/Console/Commands/*Drive*`, `*Email*`, `CheckMailAccountsHealth`.

## Schema (già nel pacchetto)

- `mail_accounts`: schema di unicooam con le colonne rinominate come in unicogdpr (`incoming_*` diventa `imap_*`, `incoming_protocol` diventa `protocol`), più l'autenticazione OAuth2 di unicogdpr: `auth_type`, `provider`, `access_token`, `refresh_token`, `token_expires_at`, `last_synced_at`, `company_id`. PEC: `is_pec` sostituisce `type = 'pec'`.
- `incoming_emails`: tabella di unicogdpr, ma con un morph `related` (reclamo, DSAR, pratica, processo) al posto di `data_subject_request_id` e `complaint_registry_id`.
- `companies.drive_folder_id` e `audits.drive_folder_id`.

## Email: cosa portare in `Unico\Core\Mail`

| Da unicogdpr | Nel pacchetto | Modifiche |
|---|---|---|
| `Contracts\ImapConnector` | `Mail\Contracts\ImapConnector` | nessuna |
| `Services\Mail\ImapConnectionFactory` | `Mail\ImapConnectionFactory` | POP3 non supportato da webklex: errore esplicito |
| `Services\Mail\OutgoingMailerFactory` | `Mail\OutgoingMailer` | nome mittente passato come parametro, non fisso "- DPO" |
| `Jobs\FetchMailAccountJob` (parte generica) | `Mail\MailFetcher` + `Mail\Jobs\FetchMailAccount` | vedi sotto |
| `Services\Mail\EmailClassifier` | `Mail\Contracts\EmailClassifier` + `Mail\RuleBasedClassifier` | regole da config, non enum GDPR |
| `Services\Mail\BounceParser`, comando `emails:process-bounces` | `Mail\BounceParser` | nessuna |
| comando `mail:health-check` | `unico-core:mail-health` | nessuna |
| `Services\SenderIdentificationService` | `Mail\SenderIdentifier` | cerca su modelli core (employees, clients, fornitoris, clientis, users); unicogdpr aggiunge i suoi |
| `Models\MailAccount`, `Models\IncomingEmail` | `Models\MailAccount`, `Models\IncomingEmail` | cast `encrypted` invariati |

`MailFetcher` mantiene la logica generica del job:
- finestra di date con sovrapposizione, invece del flag "non letta";
- deduplica per `company_id` + `message_id` (anche sui record cancellati);
- thread da `References` e `In-Reply-To`;
- decodifica MIME di oggetto e nomi degli allegati;
- allegati nella media collection `email_attachments`;
- flag `Seen` e `last_synced_at`.

Dopo il salvataggio emette l'evento `IncomingEmailReceived`. Le app reagiscono con i propri listener:
- **unicogdpr**: crea DSAR e reclami (la logica attuale di `createComplaintFromEmail` e `DataSubjectRequest::createRequest`);
- **unicobpm**: `ProcessIncomingEmails` oggi apre una propria connessione Webklex. Va riscritto come listener che collega gli allegati alle `process_instances` con il `DocumentClassifier` esistente;
- **unicofinance**: allegati a pratiche e documenti.

## Google Drive: cosa portare in `Unico\Core\Drive`

| Da unicogdpr | Nel pacchetto | Modifiche |
|---|---|---|
| credenziali lette in 6 classi diverse | `Drive\GoogleDriveFactory` | un solo punto, path da `config('unico-core.drive.credentials')`; `setSubject()` opzionale per la delega a livello di dominio |
| `Console\Commands\UploadToDrive` usato come servizio | `Drive\DriveUploader` | il comando diventa un wrapper |
| `findOrCreateChildFolder` duplicato | `Drive\DriveFolders::ensure($parentId, 'A/B/C')` | sempre `supportsAllDrives` e `includeItemsFromAllDrives` |
| `Services\Drive\CompanyDriveFolderProvisioner` | `Drive\CompanyFolderProvisioner` | cartella madre e sottocartelle da config, per app; niente ID fisso nel codice |
| `Services\Drive\AuditDriveFolderProvisioner` | `Drive\AuditFolderProvisioner` | `audits` è già nel core |
| `Services\Drive\DocumentDriveSync` | `Drive\DocumentDriveSync` | usa `documents.document_url`, `app_id`, `app_drive_id`, `source_app`, `sync_status`, già presenti nel core |
| `Services\Drive\ComplaintEmailDriveArchiver` | `Drive\EmailDriveArchiver` | sottocartella per classificazione da config; il fallback su disco locale resta |
| `Services\Drive\GoogleDriveZipExporter` | `Drive\ZipExporter` | nessuna |

## Difetti da correggere durante il porting

1. **`supportsAllDrives` usato in modo incoerente.**
   - `CompanyDriveFolderProvisioner` e `DocumentDriveSync` non passano `supportsAllDrives` e `includeItemsFromAllDrives`. Sui Drive condivisi la ricerca della cartella non trova nulla e ne crea un duplicato.
   - `ComplaintEmailDriveArchiver` e `UploadToDrive` invece li passano.
2. **Quota del service account.** L'errore `storageQuotaExceeded` documentato in `DocumentDriveSync` ha due soluzioni: usare solo Drive condivisi, oppure la delega a livello di dominio con `setSubject()`. Con la delega i file appartengono a un utente reale del dominio Workspace.
3. **ID della cartella madre scritto nel codice.** `CompanyDriveFolderProvisioner` contiene `1t7IRZmAUZ64hy9vmNJnCyhdjZzU9FdQ6`.
4. **Nome del disco ingannevole.** Il disco `google` in `config/filesystems.php` punta in realtà a Cloudflare R2. Nel pacchetto non va usato il nome `google` per lo storage dei media.

## Dipendenze da aggiungere al pacchetto

- `webklex/laravel-imap` ^6.2
- `google/apiclient` ^2.20, con lo script `Google\Task\Composer::cleanup` per tenere solo il servizio Drive (il pacchetto completo pesa centinaia di MB)
- `laravel/socialite` con `socialiteproviders/google` e `socialiteproviders/microsoft` per il rinnovo dei token OAuth2
- `spatie/laravel-medialibrary` ^11

## Test

unicogdpr ha già test per la parte email, da portare insieme al codice: `FetchMailAccountJobTest`, `ImapConnectionFactoryTest`, `IncomingEmailAttachmentTest`, `MailAccountSmtpTest`, `CheckMailAccountsHealthTest`, `SenderIdentificationServiceTest`, `BounceParserTest`, `EmailClassifierTest`. Simulano la casella tramite l'interfaccia `ImapConnector`.

Per Google Drive non ci sono test: vanno scritti nel pacchetto simulando `Google\Service\Drive`. Il primo da scrivere verifica che `DriveFolders::ensure` riusi una cartella esistente su un Drive condiviso.
