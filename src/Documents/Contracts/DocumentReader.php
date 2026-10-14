<?php

namespace Unico\Core\Documents\Contracts;

use Illuminate\Support\Collection;
use Unico\Core\Models\DocumentType;

/** Lettura di un documento con AI. Senza chiave configurata l'app riceve `NullDocumentReader`. */
interface DocumentReader
{
    public function enabled(): bool;

    /**
     * Estrae i dati di un documento (foto o PDF).
     *
     * @param  string  $kind  profilo di lettura (identita, codice_fiscale, reddito, informativa...)
     * @return array<string,mixed>|null null se non è stato possibile leggerlo
     */
    public function read(string $kind, string $mime, string $bytes): ?array;

    /**
     * Sceglie fra i tipi candidati quello a cui corrisponde il documento.
     *
     * @param  Collection<int, DocumentType>  $candidates
     * @return array{type_id: int|null, confidence: int, abstract: string|null}|null
     */
    public function classify(Collection $candidates, string $mime, string $bytes): ?array;

    /** Consumo dell'ultima chiamata (anche se la risposta non è stata utilizzabile). */
    public function lastUsage(): ?array;
}
