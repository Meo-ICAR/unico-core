# Documenti e tipi documento

`documents` e `document_types` sono il centro di tutte le app: compliance (unicooam), istruttoria e firma (unicoloan), processi (unicobpm), privacy (unicogdpr), richieste delle banche (proforma) e raccolta via WhatsApp (unicoagent). Ogni app ne ha sviluppato una parte meglio delle altre. Questo documento fissa lo schema unico e indica, funzione per funzione, quale implementazione portare nel pacchetto.

## Lo schema

| Tabella | Ruolo | Da dove arriva |
|---|---|---|
| `document_types` | Catalogo: cosa è un documento, a chi si applica, quanto vale, come si verifica | unicoloan/unicooam, più `pratica_requisiti` di unicoloan e i profili AI di unicoagent |
| `documents` | Il documento concreto, legato a qualsiasi record (morph `documentable`) | unicoloan/unicooam |
| `document_requirements` | Regole: quali tipi servono per prodotto, sottoprodotto o task | `requisito_tipo_finanziamento` e `task_document_types` di unicoloan/unicooam |
| `document_requests` | Richieste aperte su una pratica o un'anagrafica, con stato e documento che le evade | `pratica_document_requests` (proforma), `pratica_requisiti_operativi` (unicoloan), `pratica_documents` (unicoagent) |
| `document_analyses` | Storico delle verifiche automatiche e manuali, con costi AI | `attachments` (unicoagent), `metadata.verifica` (unicoloan), classificatore di unicobpm |
| `document_reminders`, `document_schedules` | Solleciti e scadenziario | unicooam |
| `signature_requests`, `signature_request_signers` | Firma elettronica (Yousign) | unicoloan |
| `pdf_modules`, `pdf_module_fields` | Moduli PDF compilabili collegati a un tipo documento | unicoloan |

**Tabelle che spariscono, fuse nelle precedenti**
- `pratica_requisiti` entra in `document_types`: tutti i requisiti (certificato di stipendio, polizze, perizia...) sono documenti.
- `requisito_tipo_finanziamento` e `task_document_types` entrano in `document_requirements`.
- `pratica_requisiti_operativi` e `pratica_document_requests` entrano in `document_requests`.

**Modifiche di colonna rispetto agli originali**
- Nomi in snake_case: `is_AiAbstract` diventa `is_ai_abstract`, `is_AiCheck` → `is_ai_check`, `AiPattern` → `ai_pattern`, `is_endMonth` → `is_end_month`.
- `documents.renewed_by_id` diventa `replaced_by_id`: indica il documento che sostituisce questo (es. la versione firmata). Il nome vecchio si confondeva con `document_types.renewed_by_id`, che indica invece il *tipo* che rinnova.
- `document_types.expire_days_before` (solo unicobpm) è eliminata. La copre `notify_days_before`, che accetta più soglie, es. `[30, 15, 5]`.
- `documents.document_url` passa da 255 a 2048 caratteri. Oggi gli URL SharePoint più lunghi finiscono in `metadata.web_url`, con un accessor apposito.
- **Colonne nuove:**
  - `documents.channel` e `channel_ref`: come è arrivato il documento (upload, email, WhatsApp, Drive, SharePoint, generato, firma) e il riferimento nel canale;
  - `document_types.company_id`: tipi specifici di un tenant accanto al catalogo globale;
  - `document_types.ai_kind` e `checks`: profilo di lettura e controlli di unicoagent;
  - indici unici su `document_types.code` e `slug`.
- `documents.status` usa i valori di `DocumentStatus` di unicooam/unicoloan, con default `caricato`.

## Il meglio di ogni app, funzione per funzione

| Funzione | Da prendere da | Perché | Note |
|---|---|---|---|
| Catalogo tipi (applicabilità, trigger, durata, fine mese, firma, conservazione, retention) | unicoloan/unicooam `DocumentType` | è il più completo; unicobpm aggiunge `is_versioned` e `training_*` nei fillable | portare `durationCalculate()` con le correzioni sotto |
| Rinnovo di un documento | unicooam `Document::renew($emittedAt)` | accetta la data di emissione e gestisce i default nulli | — |
| Plico: un file, più documenti | unicooam `createBundleCopies()` | — | in unicogdpr la propagazione di `document_url` ai documenti con lo stesso URL fa la stessa cosa in un altro modo: tenere solo `bundle_id` |
| URL di download unico (file locale o remoto) | unicooam `resolvedUrl` / `downloadUrl` + `DocumentDownloadController` | — | con `document_url` a 2048 caratteri, `metadata.web_url` non serve più |
| Scadenzario e solleciti | unicooam `DocumentReminderService` | esclude le versioni superate (un documento rinnovato non viene più sollecitato); quello di unicoloan no | — |
| Destinatari dei solleciti | `DocumentRecipientResolver` (identico in unicooam e unicoloan) | — | — |
| Pulizia dello scadenziario | unicoloan, evento `deleted` di `Document` | un documento cancellato o sostituito esce subito da `document_schedules` | — |
| Controlli al caricamento (file mancante o illeggibile, duplicato via SHA-256, scaduto, firma) | unicoloan `DocumentVerifier` + listener `VerifyUploadedDocument` | — | il risultato va in `document_analyses` invece che in `metadata.verifica` |
| Lettura AI e controlli a plug-in (tipo documento, dati coerenti, codice fiscale, IBAN, maggiorenne, informativa firmata, estrazione dati) | unicoagent `DocumentPipeline`, `CheckRegistry`, `Checks/*`, `AnthropicDocumentReader` | legge il documento una sola volta, valida l'output con uno schema JSON e registra modello, token e costo | il profilo di lettura passa da `finanziamento_documents.ai_kind` a `document_types.ai_kind` |
| Classificazione di un allegato (a quale tipo/richiesta corrisponde) | unicobpm `DocumentClassifier` | prima regex sul nome file (`document_types.regex`), poi AI solo se serve; non forza mai un match | va generalizzato da `ProcessTaskItem` a `document_requests` aperte |
| Firma elettronica | unicoloan `SignatureRequestService`, `SignatureProviderManager`, `YousignSignatureProvider`, `FakeSignatureProvider` | invio, webhook, riconciliazione, scadenze; il firmato sostituisce l'originale | media collection `signed` |
| Moduli PDF compilabili | unicoloan `PdfFieldSynchronizer`, `PraticaModuleGenerator`, `ModuleDataResolver` | — | il generatore archivia il PDF come `Document` della pratica |
| Documenti generati da template (nomine, DPA, schede) | unicogdpr `DocumentGeneratorService` (Blade → PDF) | complementare ai moduli PDF di unicoloan | resta in unicogdpr; nel pacchetto va solo il contratto `DocumentGenerator` |
| Percorsi dei file leggibili (company / tipo proprietario / istanza / categoria) | unicogdpr `DocumentPathGenerator` | i file si ritrovano anche sfogliando il disco | — |
| Disco dei file | unicogdpr disco `documenti` (privato, `serve => false`) | — | **unicooam e unicoloan salvano su `public`: carte d'identità e buste paga raggiungibili via URL** |
| Google Drive | unicogdpr `DocumentDriveSync` | — | vedi `porting-email-drive.md` |
| SharePoint | unicooam `UploadDocumentToSharePoint`, `QueueSharePointUpload`, `RetrySharePointUploads` | — | unicoagent ha un `SharePointUploader` simile: tenerne uno solo |
| Report OAM semestrale | unicooam `scopePerSemestreOam` | — | — |
| Richieste di documenti alle parti (banca → agente → cliente, con notifiche) | proforma `pratica_document_requests` | modello più ricco (chi richiede, a chi spetta, condivisione col cliente, notifiche) | ora `document_requests`, valida per qualsiasi record |

## Difetti da correggere durante il porting

1. **Stato mai confrontato correttamente.** In unicooam e unicoloan l'hook `saving` confronta `status` con l'enum `DocumentStatus`, ma `status` non ha il cast all'enum. Il confronto è sempre falso: "richiesto + data di emissione ⇒ approvato" e "respinto ⇒ nota obbligatoria" non scattano mai.
2. **Stati in due lingue.**
   - Il default della colonna era `uploaded`.
   - `renew()` scrive `approved` ed `expired`.
   - `DocumentReminderService` filtra `status != 'expired'`.
   - L'enum invece usa `approvato` e `scaduto`.

   Nel core resta solo l'enum, con cast sul modello.
3. **`Document::renewedBy()` sbagliato.** Legge `renewed_by_id` dalla relazione invece che dal modello. Poi `DocumentType::find($id)->first()` restituisce il primo tipo della tabella, non quello cercato.
4. **`durationCalculate()` incompleto.** Ignora l'unità `hours`, prevista nel commento della colonna. In unicooam e unicoloan ricalcola `expires_at` a ogni salvataggio, sovrascrivendo una scadenza inserita a mano. unicobpm e unicogdpr la calcolano solo se è vuota: tenere questo comportamento.
5. **File su disco pubblico.** In unicooam e unicoloan la collection `documents` usa il disco `public`, quindi i documenti personali sono raggiungibili via URL. Va usato un disco privato con download autenticato, che `DocumentDownloadController` già fornisce.
6. **Tre classificatori separati.** Regex sul nome file (unicobpm), profilo AI (unicoagent) e verifica tecnica (unicoloan) non si parlano. Nel core diventano tre passi di una sola pipeline, che scrive in `document_analyses`.

## unicoagent

unicoagent resta separata con il proprio database. La sua pipeline di lettura e controllo è però la migliore della suite e va portata nel pacchetto come servizio generico su `Document`. unicoagent continuerà a usare la propria copia finché non si collega alle app Unico via API. A quel punto i documenti raccolti su WhatsApp arrivano come `documents` con `channel = whatsapp` e `channel_ref` = media id, e le richieste evase aggiornano `document_requests`.
