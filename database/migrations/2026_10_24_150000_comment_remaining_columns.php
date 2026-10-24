<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ID = 'Identificativo univoco del record';

    private const CREATO = 'Data e ora di creazione del record';

    private const AGGIORNATO = 'Data e ora dell\'ultima modifica del record';

    /**
     * Connessione del database core, configurabile in config/unico-core.php.
     */
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    /**
     * Commenti alle colonne delle tabelle di unicobpm, backups3 e agent_api_clients create senza: nel database condiviso ogni
     * colonna ha un commento. Si aggiungono solo dove manca, lasciando il resto della definizione com'è.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $db = DB::connection($this->getConnection());

        foreach ($this->commenti() as $tabella => $colonne) {
            if (! $schema->hasTable($tabella)) {
                continue;
            }

            foreach ($colonne as $colonna => $commento) {
                $definizione = $db->selectOne("SHOW FULL COLUMNS FROM `{$tabella}` WHERE Field = ?", [$colonna]);

                if ($definizione === null || filled($definizione->Comment)) {
                    continue;
                }

                $db->statement("ALTER TABLE `{$tabella}` MODIFY COLUMN `{$colonna}` ".$this->definizione($definizione, $commento, $db));
            }
        }
    }

    public function down(): void {}

    /** Tipo, nullabilità, predefinito e extra della colonna com'è, più il commento. */
    private function definizione(object $colonna, string $commento, $db): string
    {
        $sql = $colonna->Type;

        if ($colonna->Collation !== null) {
            $sql .= " COLLATE {$colonna->Collation}";
        }

        $sql .= $colonna->Null === 'NO' ? ' NOT NULL' : ' NULL';

        if ($colonna->Default !== null) {
            $sql .= str_contains(strtoupper((string) $colonna->Default), 'CURRENT_TIMESTAMP') ? ' DEFAULT '.$colonna->Default : ' DEFAULT '.$db->getPdo()->quote((string) $colonna->Default);
        } elseif ($colonna->Null === 'YES') {
            $sql .= ' DEFAULT NULL';
        }

        $extra = trim(str_ireplace(['DEFAULT_GENERATED'], '', (string) $colonna->Extra));

        if ($extra !== '') {
            $sql .= ' '.$extra;
        }

        return $sql.' COMMENT '.$db->getPdo()->quote($commento);
    }

    /** @return array<string, array<string, string>> */
    private function commenti(): array
    {
        $id = self::ID;
        $creato = self::CREATO;
        $aggiornato = self::AGGIORNATO;

        return [
            'agent_api_clients' => ['id' => $id, 'created_at' => $creato, 'updated_at' => $aggiornato],
            'api_logs' => [
                'id' => $id, 'method' => 'Metodo HTTP della chiamata', 'url' => 'Indirizzo chiamato', 'request_body' => 'Corpo della richiesta (senza credenziali)',
                'status_code' => 'Codice HTTP della risposta', 'response_headers' => 'Intestazioni della risposta', 'response_body' => 'Corpo della risposta',
                'duration_ms' => 'Durata della chiamata in millisecondi', 'correlation_id' => 'Identificativo di correlazione che si ritrova nei log delle app',
                'ai_model' => 'Modello AI usato, se la chiamata è all\'AI', 'ai_input_tokens' => 'Token in ingresso della chiamata AI', 'ai_output_tokens' => 'Token in uscita della chiamata AI',
                'created_at' => $creato, 'updated_at' => $aggiornato,
            ],
            'backupenv' => ['id' => $id, 'created_at' => $creato, 'updated_at' => $aggiornato],
            'backupenv_files' => [
                'id' => $id, 'backupenv_id' => 'File .env di riferimento (backupenv.id)', 'size_bytes' => 'Dimensione della copia in byte',
                'r2_last_modified_at' => 'Ultima modifica della copia su R2', 'created_at' => $creato, 'updated_at' => $aggiornato,
            ],
            'backupenv_restores' => [
                'id' => $id, 'backupenv_file_id' => 'Copia ripristinata (backupenv_files.id)', 'overwrites_original' => 'Il ripristino ha sovrascritto il file originale',
                'requested_by' => 'Utente che ha chiesto il ripristino', 'started_at' => 'Inizio del ripristino', 'finished_at' => 'Fine del ripristino',
                'error' => 'Messaggio di errore, se il ripristino è fallito', 'created_at' => $creato, 'updated_at' => $aggiornato,
            ],
            'employee_types' => [
                'description' => 'Nome esteso dell\'ufficio (es. Funzione Compliance)', 'mission' => 'Cosa fa l\'ufficio', 'responsibility' => 'Attività e responsabilità dell\'ufficio',
                'manager_type' => 'Alias del modello del responsabile (employee o client)', 'manager_id' => 'Identificativo del responsabile dell\'ufficio',
            ],
            'external_events' => [
                'id' => $id, 'data' => 'Dati dell\'evento, in JSON', 'occurred_at' => 'Quando è avvenuto l\'evento, secondo il mittente',
                'started_count' => 'Quanti processi ha avviato l\'evento', 'created_at' => $creato, 'updated_at' => $aggiornato,
            ],
            'listini_provvigionali_voci' => ['listino_provvigionale_id' => 'Listino provvigionale a cui appartiene la voce'],
            'notifications' => [
                'notifiable_type' => 'Alias del modello che riceve la notifica', 'notifiable_id' => 'Identificativo di chi riceve la notifica',
                'created_at' => $creato, 'updated_at' => $aggiornato,
            ],
            'outbound_calls' => [
                'id' => $id, 'method' => 'Metodo HTTP della chiamata', 'payload' => 'Contenuto da inviare, in JSON', 'correlation_id' => 'Identificativo di correlazione della chiamata',
                'attempts' => 'Tentativi di consegna già fatti', 'last_status_code' => 'Codice HTTP dell\'ultimo tentativo', 'last_error' => 'Errore dell\'ultimo tentativo',
                'next_attempt_at' => 'Quando si ritenta la consegna', 'sent_at' => 'Quando è stata consegnata', 'created_at' => $creato, 'updated_at' => $aggiornato,
            ],
            'process_email_replies' => [
                'id' => $id, 'process_instance_id' => 'Pratica a cui risponde l\'email', 'process_task_execution_id' => 'Passo a cui risponde l\'email',
                'from_email' => 'Indirizzo di chi ha risposto', 'subject' => 'Oggetto della risposta', 'confidence' => 'Sicurezza dell\'interpretazione, da 0 a 100',
                'summary' => 'Sintesi di ciò che la risposta dice', 'decided_at' => 'Quando la risposta è stata decisa (da regole, AI o operatore)',
                'created_at' => $creato, 'updated_at' => $aggiornato,
            ],
            'process_postponements' => [
                'id' => $id, 'process_instance_id' => 'Pratica di cui si rinvia il termine', 'process_task_execution_id' => 'Passo di cui si rinvia il termine',
                'reason' => 'Motivo del rinvio', 'created_at' => $creato, 'updated_at' => $aggiornato,
            ],
            'process_task_executions' => ['postponed_until' => 'Rinvio concordato: il termine non scade prima di questa data'],
            'process_triggers' => ['model_class' => 'Modello locale che avvia il processo; vuoto per i trigger su eventi di altre app'],
            'webhook_endpoints' => [
                'id' => $id, 'name' => 'Nome del destinatario', 'is_active' => 'Se il destinatario riceve gli eventi',
                'created_at' => $creato, 'updated_at' => $aggiornato,
            ],
        ];
    }
};
