<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Connessione del database core, configurabile in config/unico-core.php.
     */
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    /**
     * Il ruolo è anche l'ufficio che risponde delle attività (prima la funzione aziendale): codice, area, email dell'ufficio,
     * missione e responsabilità, se può essere ricoperto da un esterno e chi ne è il responsabile (dipendente o esterno).
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasTable('employee_types') || $schema->hasColumn('employee_types', 'code')) {
            return;
        }

        $schema->table('employee_types', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->unique()->after('name')->comment('Codice dell\'ufficio (es. COMPLIANCE), usato dai processi');
            $table->string('macro_area')->nullable()->after('code')->comment('Area (es. Governance, Business, Controllo)');
            $table->string('type')->nullable()->after('macro_area')->comment('Tipo di funzione (es. Strategica, Operativa, Controllo)');
            $table->text('description')->nullable()->after('type');
            $table->string('outsourcable_status', 20)->default('no')->after('description')->comment('Può essere ricoperto anche da un esterno (client): no, yes, partial');
            $table->string('managed_by_code', 50)->nullable()->after('outsourcable_status')->comment('Codice del ruolo che lo supervisiona');
            $table->text('mission')->nullable()->after('managed_by_code');
            $table->text('responsibility')->nullable()->after('mission');
            $table->string('email')->nullable()->after('responsibility')->comment('Email dell\'ufficio, usata se nessuno ricopre il ruolo');
            $table->nullableMorphs('manager');
        });
    }

    public function down(): void
    {
        // I campi restano: contengono i dati degli uffici.
    }
};
