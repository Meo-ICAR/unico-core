<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le fasi del documento da firmare stanno tutte in `documents.status`: dati_mancanti, stampare, scaricare, da_inviare_otp, inviato_otp,
 * firmato_otp, da_approvare_otp, caricato. I booleani `is_printable` e `is_signed` spariscono: «firmato» è `signed_at` (o la fase).
 * Un `is_signed` copiato dal tipo senza una firma vera (nessuna data né richiesta firmata) non vale come firmato.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        $hasSigned = Schema::hasColumn('documents', 'is_signed');
        $hasPrintable = Schema::hasColumn('documents', 'is_printable');

        if ($hasSigned) {
            // La data di firma, se manca, è quella della richiesta di firma completata.
            $db->table('documents')->where('is_signed', true)->whereNull('signed_at')->orderBy('id')->each(function ($document) use ($db): void {
                $signedAt = $db->table('signature_requests')->where('document_id', $document->id)->where('status', 'signed')->orderByDesc('id')->value('signed_at');

                if ($signedAt !== null) {
                    $db->table('documents')->where('id', $document->id)->update(['signed_at' => $signedAt]);
                }
            });
        }

        $inCorso = ['richiesto', 'caricato'];

        $db->table('documents')->whereNotNull('signed_at')->whereIn('status', $inCorso)->update(['status' => 'firmato_otp']);
        $db->table('documents')
            ->whereNotNull('signed_at')->where('status', 'firmato_otp')->whereNull('approved_at')
            ->whereIn('document_type_id', $db->table('document_types')->whereNotNull('approver_model')->select('id'))
            ->update(['status' => 'da_approvare_otp']);

        $inviati = $db->table('signature_requests')->whereIn('status', ['pending', 'sent'])->select('document_id');
        $db->table('documents')->whereNull('signed_at')->whereIn('status', $inCorso)->whereIn('id', $inviati)->update(['status' => 'inviato_otp']);

        if ($hasPrintable) {
            $db->table('documents')->where('is_printable', true)->whereNull('signed_at')->whereIn('status', $inCorso)->update(['status' => 'da_inviare_otp']);
        }

        Schema::table('documents', function (Blueprint $table) use ($hasSigned, $hasPrintable): void {
            $table->string('status', 50)->default('caricato')->comment('Valori di DocumentStatus: na, richiesto, caricato, approvato, respinto, provvisorio, scaduto, illegibile, revocato, anomalo, integrativo; fasi della firma: dati_mancanti, stampare, scaricare, da_inviare_otp, inviato_otp, firmato_otp, da_approvare_otp')->change();

            foreach (array_filter(['is_printable' => $hasPrintable, 'is_signed' => $hasSigned]) as $column => $_) {
                $table->dropColumn($column);
            }
        });
    }

    public function down(): void
    {
        $db = DB::connection($this->getConnection());

        Schema::table('documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('documents', 'is_signed')) {
                $table->boolean('is_signed')->default(false)->after('cellposition')->comment('Il documento è stato firmato (nel tipo: deve essere firmato)');
            }

            if (! Schema::hasColumn('documents', 'is_printable')) {
                $table->boolean('is_printable')->default(false)->after('is_signed')->comment('Modulo da firmare pronto per la stampa: tutti i campi obbligatori del modulo sono valorizzati nel record associato');
            }
        });

        $db->table('documents')->where(fn ($query) => $query->whereNotNull('signed_at')->orWhereIn('status', ['firmato_otp', 'da_approvare_otp']))->update(['is_signed' => true]);
        $db->table('documents')->whereIn('status', ['stampare', 'da_inviare_otp'])->update(['is_printable' => true]);
        $db->table('documents')->whereIn('status', ['dati_mancanti', 'stampare', 'scaricare', 'da_inviare_otp', 'inviato_otp', 'firmato_otp', 'da_approvare_otp'])->update(['status' => 'caricato']);
    }
};
