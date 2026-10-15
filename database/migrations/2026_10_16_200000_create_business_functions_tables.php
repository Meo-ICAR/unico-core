<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Funzioni aziendali (funzionigramma) e loro membri, condivise da tutte le app: unicobpm le usa per la matrice RACI dei processi,
 * le altre per sapere chi ricopre un ruolo. I membri sono dipendenti (`employee`) o consulenti esterni (`client`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('business_functions')) {
            Schema::create('business_functions', function (Blueprint $table) {
                $table->comment('Funzioni aziendali del funzionigramma, condivise tra le app');
                $table->id()->comment('ID univoco funzione business');
                $table->string('code')->unique()->comment('Codice identificativo univoco funzione');
                $table->enum('macro_area', ['Governance', 'Business / Commerciale', 'Supporto', 'Controlli (II Livello)', 'Controlli (III Livello)', 'Controlli / Privacy'])->default('Supporto')->comment('Macro area di appartenenza');
                $table->string('name')->nullable()->unique()->comment('Nome specifico funzione business');
                $table->enum('type', ['Strategica', 'Operativa', 'Supporto', 'Controllo'])->default('Operativa')->comment('Tipologia funzione');
                $table->text('description')->nullable()->comment('Descrizione dettagliata funzione');
                $table->enum('outsourcable_status', ['yes', 'no', 'partial'])->default('no')->comment('La funzione può essere esternalizzata: sì, no, in parte');
                $table->string('managed_by_code')->nullable()->comment('Codice della funzione che la gestisce');
                $table->longText('mission')->nullable()->comment('Cosa fa la funzione');
                $table->longText('responsibility')->nullable()->comment('Elenco di attività e responsabilità');
                $table->string('email')->nullable()->comment('Email di contatto della funzione');
                $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
                $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            });
        }

        if (! Schema::hasTable('business_function_members')) {
            Schema::create('business_function_members', function (Blueprint $table) {
                $table->comment('Membri di una funzione aziendale: dipendenti o consulenti esterni, con il ruolo che ricoprono');
                $table->id()->comment('Identificativo univoco del record');
                $table->unsignedBigInteger('business_function_id')->comment('Funzione aziendale');
                $table->unsignedBigInteger('employee_type_id')->nullable()->index()->comment('Ruolo ricoperto nella funzione (employee_types.id)');
                $table->string('member_type', 50)->comment('Tipo del membro: alias employee o client');
                $table->unsignedBigInteger('member_id')->comment('Identificativo del membro (dipendente o cliente consulente)');
                $table->boolean('is_manager')->default(false)->comment('Il membro è il responsabile della funzione');
                $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
                $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
                $table->foreign('business_function_id')->references('id')->on('business_functions')->cascadeOnDelete();
                $table->unique(['business_function_id', 'member_id', 'member_type'], 'unique_business_function_member');
                $table->index(['member_type', 'member_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_function_members');
        Schema::dropIfExists('business_functions');
    }
};
