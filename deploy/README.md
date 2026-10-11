# Worker e scheduler delle app Unico

Cosa serve perché giri tutto ciò che è «in coda» o «pianificato»: lanci del bridge, analisi documenti, consegne a unicobpm e verso le altre app,
solleciti, risposte email, download delle caselle, escalation, backup notturno. Richiede privilegi di amministratore.

## File
- `supervisor/unico-<app>-queue.conf`: un worker per app (stesso stile di `whitelist-queue`). unicoloan e unicooam ascoltano anche la coda `documents`
  (analisi dei documenti); la coda del bridge e delle altre attività è `default`.
- `cron.d-unico-scheduler`: `schedule:run` ogni minuto per ogni app (unicobpm, unicoloan, unicooam, proforma, unicoagent, backups3: quest’ultima non ha code, solo il backup notturno).

## Installazione
```bash
sudo cp deploy/supervisor/unico-*-queue.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
sudo cp deploy/cron.d-unico-scheduler /etc/cron.d/unico-scheduler
sudo chown root:root /etc/cron.d/unico-scheduler && sudo chmod 644 /etc/cron.d/unico-scheduler
```
I processi girano come `www-data`: le cartelle `storage/` e `bootstrap/cache` di ogni app devono essere scrivibili da quell'utente
(oggi lo sono: permessi di gruppo e `777`). Il worker non vede il codice nuovo finché non si riavvia: dopo ogni deploy
`php artisan queue:restart` (o `sudo supervisorctl restart 'unico-*'`).

## Verifica
```bash
sudo supervisorctl status | grep unico-
tail -f /var/www/html/unicobpm/storage/logs/queue-worker.log
php artisan schedule:list      # in ogni app: cosa è pianificato
```
## Modifiche
Per un'app in più si copia un file `.conf` cambiando nome programma, cartella e log, e si aggiunge una riga al cron.
