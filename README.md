# unico-core

Tabelle condivise della suite Unico: unicoloan, proforma, unicooam, unicobpm e, per le funzioni email e Drive, unicogdpr.
Ogni app resta un progetto Laravel separato e mostra le stesse tabelle con le proprie risorse Filament.

unicoagent non usa il core: è un'app separata con il proprio database, che si collega ai CRM (incluse le app Unico) tramite API.

## Contenuto

- `database/migrations/`: 64 tabelle core in 7 aree e 113 chiavi esterne, aggiunte in una migration finale
- `docs/porting-email-drive.md`: piano per portare nel pacchetto la lettura email e la gestione di Google Drive da unicogdpr
- `docs/schema-map.md`: per ogni tabella, da quali app arriva ogni colonna e quali conversioni sono state fatte
- `tools/schema-merge/`: script che rigenerano le migration dai dump e dalle regole in `spec.py`

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
- **Una tabella per concetto, con tutti i campi.** Per ogni tabella la prima sorgente elencata in `docs/schema-map.md` decide tipi e vincoli. Le colonne che arrivano solo dalle altre app sono aggiunte come nullable.
- **Tabelle fuse.** `pratiches_statos` in `pratica_stati`; `client_pratiches` in `pratica_clients`; `tipo_prodottos` in `tipoprodotto`.
- **Campi sinonimi uniti.** `companies.piva` in `vat_number`; `fornitoris.company_branch_id` in `branch_id`; in `mail_accounts` le colonne `incoming_*` in `imap_*`.
- **Email.** `mail_accounts` unisce lo schema di unicooam con l'autenticazione OAuth2 di unicogdpr. `incoming_emails` arriva da unicogdpr, con un morph `related` al posto dei collegamenti a DSAR e reclami.
- **Permessi.** Si usa il modello di unicobpm: `employee_type_permissions.resource_id` con FK su `resources`. La colonna testuale `resource` di unicoloan e unicooam è eliminata.
- **Pratiche.** `stato_pratica` e `tipo_prodotto` (testo) sono sostituiti da `pratica_stato_id` e `tipoprodotto_id`. Sono aggiunti `company_id`, `fornitori_id` e `clienti_id` per collegare la pratica a tenant, agente e banca.
- **Default rimossi.** Il tenant fisso `5c044917-…` su `clientis` e `fornitoris`; l'hash di password predefinito su `users.password`.
- **`companies.db_password`** è `text`, per poter usare il cast `encrypted` nel modello.

## Da fare nelle app

1. Modelli Eloquent condivisi in questo pacchetto, con `protected $connection` letta da `config('unico-core.connection')`.
2. `Relation::enforceMorphMap()` con alias stabili (`company`, `client`, `fornitore`, `employee`, ...): oggi ogni app salva nomi di classe diversi nei campi `*_type`.
3. Togliere le tabelle core dalle migration delle singole app e puntare i modelli locali al pacchetto.
4. Riscrivere le 34 viste di proforma: usano `pratiches_statos`, `stato_pratica` e gli ID UUID.
5. Se le app usano lo stesso database: dare a ciascuna nomi propri per le tabelle di framework (sessioni, cache, code, `migrations`). Non usare mai `migrate:fresh` o `db:wipe`, che svuotano l'intero database.
