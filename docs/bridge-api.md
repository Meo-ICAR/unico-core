# Bridge per sistemi esterni (unicobpm e app di terzi)

Rotte sotto `/api`, attive con `UNICO_CORE_BRIDGE=true`. Autenticazione: intestazione `X-Api-Key` = `BPM_BRIDGE_API_KEY` (senza chiave configurata rispondono 503).
Ogni risposta riporta `X-Correlation-Id`: chi chiama può mandarne uno (8–64 caratteri `A-Za-z0-9._-`) e lo ritrova nei log di tutte le app.

| Metodo e percorso | Cosa fa |
|---|---|
| `GET /api/commands` | Comandi consentiti: `{commands: {nome: {description, options[]}}}` |
| `POST /api/commands/{comando}` | Mette in coda il comando. Corpo: `{"options": {"start-date": "2026-01-01"}}`. Risposta `202` con `run_id` e `status_url`. `404` comando non consentito, `422` opzioni non consentite o non scalari (con `allowed_options`) |
| `GET /api/commands/runs/{run_id}` | Esito del lancio: `status` (`queued`, `running`, `succeeded`, `failed`, `skipped`), `finished`, `exit_code`, `attempts`, `output` (ultimi 4000 caratteri), `started_at`, `finished_at`, `correlation_id` |
| `GET /api/bridge/health` | `{app, status: "ok", time}` |

Il lancio è in coda (`unico-core.bridge.queue`): fallimenti ritentati 3 volte con attesa 30/120/300 secondi, finché restano tentativi lo stato è `queued`.
Due lanci dello stesso comando non si sovrappongono: il secondo diventa `skipped`.

## Configurazione
- `unico-core.bridge.commands`: whitelist `nome => ['description' => ..., 'options' => [...]]`; si somma a `config('schedulable_commands')`.
- `unico-core.bridge.middleware`: di default correlazione + `api` + chiave. Un'app può sostituirlo (es. con la propria autenticazione).
- `unico-core.bridge.keep_runs_days` (30): `CommandDispatcher::prune()` elimina i lanci più vecchi.
