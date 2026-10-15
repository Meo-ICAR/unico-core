<?php

namespace Unico\Core\Documents;

use RuntimeException;
use Unico\Core\Documents\Listeners\AnalyzeUploadedDocument;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\Pratica;
use ZipArchive;

/**
 * Plico di una pratica da mandare in istruttoria: un archivio ZIP con i documenti raccolti e la modulistica firmata (del documento
 * firmato si prende la versione firmata), più un indice. Si archivia come documento della pratica, di tipo «Plico pratica».
 */
class PracticeDossier
{
    public const TYPE_CODE = 'PLICO_PRATICA';

    /** Documenti che entrano nel plico, con il file da inserire. */
    public function contenuto(Pratica $pratica): array
    {
        $tipoPlico = DocumentType::query()->where('code', self::TYPE_CODE)->value('id');

        return Document::query()
            ->where('documentable_type', 'pratica')
            ->where('documentable_id', (string) $pratica->getKey())
            ->when($tipoPlico !== null, fn ($query) => $query->where('document_type_id', '!=', $tipoPlico))
            ->whereNotIn('status', [DocumentStatus::NA->value, DocumentStatus::REJECTED->value, DocumentStatus::REVOKED->value])
            ->with('documentType')
            ->orderBy('id')
            ->get()
            ->map(fn (Document $document): array => ['documento' => $document, 'media' => $document->currentMedia()])
            ->filter(fn (array $riga): bool => $riga['media'] !== null)
            ->values()
            ->all();
    }

    /** Crea il plico (sostituisce quello precedente della pratica) e lo restituisce. */
    public function crea(Pratica $pratica): Document
    {
        $righe = $this->contenuto($pratica);

        if ($righe === []) {
            throw new RuntimeException('La pratica non ha documenti da inserire nel plico.');
        }

        $percorso = tempnam(sys_get_temp_dir(), 'plico');
        $zip = new ZipArchive;

        if ($zip->open($percorso, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossibile creare il plico.');
        }

        $indice = ["Plico della pratica {$pratica->codice_pratica}", 'Creato il '.now()->format('d/m/Y H:i'), ''];

        foreach ($righe as $numero => $riga) {
            /** @var Document $documento */
            $documento = $riga['documento'];
            $nome = sprintf('%02d-%s.%s', $numero + 1, str($documento->name ?: 'documento')->slug(), pathinfo($riga['media']->file_name, PATHINFO_EXTENSION) ?: 'pdf');
            $zip->addFromString($nome, (string) file_get_contents($riga['media']->getPath()));
            $indice[] = $nome.' — '.($documento->documentType?->name ?? $documento->name).' ('.($documento->status?->getLabel() ?? '').($documento->signed_at ? ', firmato' : '').')';
        }

        $zip->addFromString('indice.txt', implode("\n", $indice)."\n");
        $zip->close();

        $tipo = DocumentType::query()->firstOrCreate(['code' => self::TYPE_CODE], [
            'name' => 'Plico pratica', 'slug' => 'plico-pratica', 'is_practice' => true, 'is_client' => true, 'nature' => 'outgoing',
        ]);

        Document::query()->where('documentable_type', 'pratica')->where('documentable_id', (string) $pratica->getKey())->where('document_type_id', $tipo->getKey())->get()->each->delete();

        $plico = Document::query()->create([
            'documentable_type' => 'pratica',
            'documentable_id' => (string) $pratica->getKey(),
            'document_type_id' => $tipo->getKey(),
            'company_id' => is_numeric($pratica->company_id) ? (int) $pratica->company_id : null,
            'name' => "Plico pratica {$pratica->codice_pratica}",
            'status' => DocumentStatus::UPLOADED,
        ]);

        AnalyzeUploadedDocument::suspendedWhile(fn () => $plico->addMedia($percorso)
            ->usingFileName('plico-'.str($pratica->codice_pratica)->slug().'.zip')
            ->toMediaCollection(Document::COLLECTION));

        return $plico;
    }
}
