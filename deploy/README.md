# Worker e scheduler delle app Unico

Cosa serve perché giri tutto ciò che è «in coda» o «pianificato»: lanci del bridge, analisi documenti, consegne a unicobpm e verso le altre app,
solleciti, risposte email, download delle caselle, escalation, backup notturno. Richiede privilegi di amministratore.

## File
- `supervisor/unico-<app>-queue.conf`: un worker per app (stesso stile di `whitelist-queue`). unicoloan e unicooam ascoltano anche la coda `documents`
  (analisi dei documenti); la coda del bridge e delle altre attività è `default`.
- `cron.d-unico-scheduler`: `schedule:run` ogni 10 minuti per ogni app (gli orari pianificati devono cadere su minuti multipli di 10: oggi lo sono tutti, e se ne aggiungi uno nuovo ricordalo; con `everyFiveMinutes` o `everyMinute` il comando parte comunque ogni 10 minuti) (unicobpm, unicoloan, unicooam, proforma, unicoagent, backups3: quest’ultima non ha code, solo il backup notturno).

## Installazione
```bash
sudo cp deploy/supervisor/unico-*-queue.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
sudo cp deploy/cron.d-unico-scheduler /etc/cron.d/unico-scheduler
sudo chown root:root /etc/cron.d/unico-scheduler && sudo chmod 644 /etc/cron.d/unico-scheduler
```
I processi girano come `ubuntu`, che possiede le cartelle delle app e ha creato finora i dump e i log del backup notturno (le cartelle create
da un utente con permessi `755` non sono scrivibili da un altro: per questo worker, scheduler e backup devono girare tutti con lo stesso utente).
Se i file passano a un altro utente basta cambiare `user=` nei file supervisor e la colonna utente nel cron. Il worker non vede il codice nuovo finché non si riavvia: dopo ogni deploy
`php artisan queue:restart` (o `sudo supervisorctl restart 'unico-*'`).

## Verifica
```bash
sudo supervisorctl status | grep unico-
tail -f /var/www/html/unicobpm/storage/logs/queue-worker.log
php artisan schedule:list      # in ogni app: cosa è pianificato
```
## Modifiche
Per un'app in più si copia un file `.conf` cambiando nome programma, cartella e log, e si aggiunge una riga al cron.
