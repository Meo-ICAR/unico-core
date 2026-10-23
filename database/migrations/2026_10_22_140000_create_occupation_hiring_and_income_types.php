<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anagrafiche dell'intervista preliminare (modulo prestiti personali): tipo occupazione, tipo assunzione, tipo reddito.
 */
return new class extends Migration
{
    private const TABLES = [
        'occupation_types' => [
            'comment' => 'Tipi di occupazione del cliente (intervista preliminare)',
            'name' => 'Tipo di occupazione',
            'rows' => [
                'Altro', 'Artigiani', 'Collab. domestiche / Badanti', 'Coltivatori diretti - braccianti', 'Commerciante - Rappres. - Commessi',
                'Conducenti - autisti in genere', 'Dirigente', 'Funzionario - Quadri direttivi', 'Insegnanti', 'Interinali', 'Imprenditori',
                'Militari - Vigili - Guardie giurate', 'Operaio', 'Medici e paramedici dipendenti', 'Dipendenti Postali',
                'Impiegato enti privati', 'Impiegato enti pubblici', 'Addetti - ausiliari dei servizi',
            ],
        ],
        'hiring_types' => [
            'comment' => 'Tipi di assunzione del cliente (intervista preliminare)',
            'name' => 'Tipo di assunzione',
            'rows' => ['Tempo indeterminato', 'Tempo determinato'],
        ],
        'income_types' => [
            'comment' => 'Tipi di reddito del cliente (intervista preliminare)',
            'name' => 'Tipo di reddito',
            'rows' => ['Netto', 'Lordo'],
        ],
    ];

    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $db = \Illuminate\Support\Facades\DB::connection($this->getConnection());

        foreach (self::TABLES as $table => $def) {
            if (! $schema->hasTable($table)) {
                $schema->create($table, function (Blueprint $blueprint) use ($def) {
                    $blueprint->comment($def['comment']);
                    $blueprint->id()->comment('ID univoco');
                    $blueprint->string('name', 100)->unique()->comment($def['name']);
                    $blueprint->unsignedSmallInteger('sort_order')->default(0)->comment('Ordine di visualizzazione');
                    $blueprint->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
                    $blueprint->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
                });
            }

            foreach ($def['rows'] as $order => $name) {
                $db->table($table)->insertOrIgnore(['name' => $name, 'sort_order' => $order + 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        // Prima le colonne che le usano (migration successiva): qui si tolgono solo le tabelle se nessun cliente le usa.
        foreach (array_keys(self::TABLES) as $table) {
            $schema->dropIfExists($table);
        }
    }
};
