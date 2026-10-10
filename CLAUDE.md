# unico-core

Pacchetto Composer `meo-icar/unico-core` (namespace `Unico\Core`): moduli condivisi dalle app Unico di un mediatore creditizio
(unicoagent, unicoloan, proforma, unicooam, unicobpm, unicogdpr). Stack: PHP 8.3, MySQL, Laravel 13, Filament 5, Spatie Media Library.
Le app restano separate e possono avere viste diverse sulle stesse tabelle. Tutti gli ID sono bigint interi. I database sono tutti di sviluppo.
Leggere prima `README.md` e `docs/` (schema-map, documents-design, porting-email-drive): contengono le decisioni già prese.

## Regole che non vanno rotte
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

## Test
`composer install`, un database MySQL vuoto, poi `vendor/bin/phpunit` (serve `pdftk` per i test dei moduli PDF; si salta se manca). Le variabili possono stare nel `.env` (lette da `tests/bootstrap.php`; mai committare il `.env`): `CORE_TEST_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD`
(default `127.0.0.1:3306`, `unico_core_test`, `unico`/`unico`). I test sono scritti senza un'app Laravel completa (`tests/Support/CoreDatabase.php`);
rete e servizi esterni sono sostituiti da finti (`FakeImap`, `FakeDrive`, `FakeGraph`, `FakeReader`, `FakeMailManager`, `FakeFiles`).
Non lanciare due `phpunit` insieme sullo stesso database: ognuno ricrea le tabelle. Gli errori di integrazione vanno corretti, non aggirati.

## Fatto
Schema (66 tabelle) e modelli; Document/DocumentType; email (IMAP, OAuth, classificazione, rimbalzi); Google Drive; SharePoint;
pipeline di analisi documenti (`Documents\DocumentPipeline`); scadenziario e solleciti (`Documents\Reminders`);
prima esecuzione dei test con le librerie vere (171 test verdi); `Documents\DocumentPathGenerator` (percorsi leggibili, config `documents.readable_paths`)
e contratto `DocumentGenerator`; `Mail\SenderIdentifier` (+ `SenderMatcher` per le app); moduli PDF `Pdf\PdfFormFiller` (pdftk).

## Da fare (in ordine)
1. Firma elettronica (Yousign): `SignatureRequestService`, `SignatureProviderManager`, `YousignSignatureProvider`, `FakeSignatureProvider`, DTO, webhook,
   riconciliazione e scadenze, da unicoloan (`../unicoloan/app/Services/Signature`). Prima definire enum `SignatureRequestStatus`, `SignerStatus`, `SignerRole`;
   la parte KYC di `completeRequest` resta nell'app (evento o hook), il registro attività (`activity()`) va dietro un contratto.
2. Collegare le app una alla volta, partendo da unicooam: sostituire i modelli locali con quelli del pacchetto, convertire i valori `*_type` agli alias,
   riscrivere le 34 viste di proforma, allineare proforma a Laravel 13 e Filament 5.
3. Correzioni nelle app: documenti su disco pubblico, `db_password` in chiaro, enum di stato.
4. unicoagent resta separata e parla via API (`documents.channel = 'whatsapp'`).

## Commit
I messaggi finiscono con le righe di attribuzione richieste dall'ambiente in uso.
