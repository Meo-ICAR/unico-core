<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * I portali di un istituto stanno in `websites`, per tipo: `caricamento` (dove gli istruttori inseriscono le pratiche), `modulistica`
 * (da cui si scaricano i moduli) e `api` (collegamento via API). Campi aggiunti: indirizzo completo, credenziali dell'API (la password
 * si salva cifrata) e note. Il vecchio `clientis.portal_url` diventa il portale di caricamento.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (! Schema::hasColumn('websites', 'url')) {
                $table->string('url', 500)->nullable()->after('domain')->comment('Indirizzo completo del portale (per il tipo api: la base dell\'API)');
            }

            if (! Schema::hasColumn('websites', 'api_username')) {
                $table->string('api_username', 190)->nullable()->comment('Utente per il collegamento via API al portale');
            }

            if (! Schema::hasColumn('websites', 'api_secret')) {
                $table->text('api_secret')->nullable()->comment('Password o chiave dell\'API, cifrata');
            }

            if (! Schema::hasColumn('websites', 'notes')) {
                $table->text('notes')->nullable()->comment('Note per chi usa il portale');
            }
        });

        $db = DB::connection($this->getConnection());

        $db->table('clientis')->whereNotNull('portal_url')->where('portal_url', '!=', '')->orderBy('id')->each(function ($banca) use ($db): void {
            $esiste = $db->table('websites')->where('websiteable_type', 'cliente')->where('websiteable_id', $banca->id)->where('type', 'caricamento')->exists();

            if ($esiste) {
                return;
            }

            $url = str_starts_with($banca->portal_url, 'http') ? $banca->portal_url : 'https://'.$banca->portal_url;

            $db->table('websites')->insert([
                'websiteable_type' => 'cliente', 'websiteable_id' => $banca->id, 'clienti_id' => $banca->id, 'type' => 'caricamento',
                'name' => 'Portale di caricamento pratiche', 'url' => $url, 'domain' => parse_url($url, PHP_URL_HOST) ?: $url,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            foreach (['notes', 'api_secret', 'api_username', 'url'] as $column) {
                if (Schema::hasColumn('websites', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
