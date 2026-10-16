<?php

namespace Unico\Core\Documents;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use RuntimeException;
use Unico\Core\Models\Document;
use Unico\Core\Models\Task;
use ZipArchive;

/**
 * Tutti i documenti di un plico per un record: quelli richiesti dai requisiti del task (anche ereditati dal padre) e sul model
 * giusto (il record, o i client della pratica), in un archivio ZIP con un indice. Del documento firmato si prende la versione firmata.
 */
class PlicoArchive
{
    /**
     * I documenti già presenti per il plico, con o senza file.
     *
     * @return Collection<int, Document>
     */
    public function documenti(Task $task, Model $record): Collection
    {
        $alias = $task->taskable ?: Relation::getMorphAlias($record::class);
        $documenti = collect();

        foreach ($task->requisitiEffettivi() as $requirement) {
            foreach ($requirement->ownersFor($record, $alias) as [$ownerType, $ownerId]) {
                $documenti = $documenti->concat(Document::query()
                    ->where('documentable_type', $ownerType)
                    ->where('documentable_id', $ownerId)
                    ->where('document_type_id', $requirement->document_type_id)
                    ->with('documentType')
                    ->get());
            }
        }

        return $documenti->unique(fn (Document $document) => $document->getKey())->sortBy(fn (Document $document) => $document->getKey())->values();
    }

    /**
     * Crea lo ZIP con i file dei plichi indicati e ne restituisce il percorso (file temporaneo da cancellare dopo l'invio).
     *
     * @param  iterable<Task>  $tasks
     *
     * @throws RuntimeException se nessun documento ha un file
     */
    public function zip(iterable $tasks, Model $record): string
    {
        $percorso = tempnam(sys_get_temp_dir(), 'plico');
        $zip = new ZipArchive;

        if ($zip->open($percorso, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossibile creare l\'archivio del plico.');
        }

        $indice = ['Creato il '.now()->format('d/m/Y H:i'), ''];
        $inseriti = 0;
        $usati = [];

        foreach ($tasks as $task) {
            $cartella = str($task->name)->slug()->toString();
            $indice[] = $task->name;

            foreach ($this->documenti($task, $record) as $numero => $documento) {
                $media = $documento->currentMedia();

                if ($media === null || ! is_file($media->getPath())) {
                    $indice[] = '  (manca il file) '.($documento->name ?: $documento->documentType?->name);

                    continue;
                }

                $nome = sprintf('%s/%02d-%s.%s', $cartella, $numero + 1, str($documento->name ?: 'documento')->slug(), pathinfo($media->file_name, PATHINFO_EXTENSION) ?: 'pdf');

                if (isset($usati[$nome])) {
                    continue;
                }

                $usati[$nome] = true;
                $zip->addFromString($nome, (string) file_get_contents($media->getPath()));
                $indice[] = '  '.$nome.' — '.($documento->status?->getLabel() ?? '').($documento->signed_at ? ', firmato' : '');
                $inseriti++;
            }
        }

        if ($inseriti === 0) {
            $zip->close();
            @unlink($percorso);

            throw new RuntimeException('Il plico non ha documenti con un file da scaricare.');
        }

        $zip->addFromString('indice.txt', implode("\n", $indice)."\n");
        $zip->close();

        return $percorso;
    }
}
