<?php

namespace Unico\Core\Documents;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Unico\Core\Documents\Api\DocumentApiService;
use Unico\Core\Models\Cliente;
use Unico\Core\Models\DocumentType;
use Unico\Core\MorphMap;

/**
 * Controllo di coerenza dei tipi documento: fonte e dati che la fonte richiede (servizio API, modulo PDF, file del template),
 * firma, scadenza, rinnovo e istituto. Restituisce un elenco di problemi, non corregge nulla.
 */
class DocumentTypeConsistency
{
    public const DURATION_UNITS = ['hours', 'days', 'months', 'years'];

    /** @var list<int> tipi con il file del template allegato */
    private array $withTemplateFile = [];

    public function __construct(private DocumentApiService $api) {}

    /**
     * @return list<array{id: int, name: string, problem: string}>
     */
    public function check(?Collection $types = null): array
    {
        $types ??= DocumentType::query()->with('pdfModule')->orderBy('id')->get();
        $services = $this->api->services();
        $ids = DocumentType::query()->pluck('id')->all();
        $banche = Cliente::query()->pluck('id')->all();
        $this->withTemplateFile = (new DocumentType)->getConnection()->table('media')
            ->whereIn('model_type', ['document_type', DocumentType::class, 'App\\Models\\DocumentType'])
            ->pluck('model_id')->map(fn (mixed $id): int => (int) $id)->all();
        $problems = [];

        foreach ($types as $type) {
            foreach ($this->problemsOf($type, $services, $ids, $banche) as $problem) {
                $problems[] = ['id' => (int) $type->getKey(), 'name' => (string) $type->name, 'problem' => $problem];
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, string>  $services
     * @param  array<int, int>  $ids
     * @param  array<int, int>  $banche
     * @return list<string>
     */
    public function problemsOf(DocumentType $type, array $services = [], array $ids = [], array $banche = []): array
    {
        $problems = [];
        $module = $type->pdfModule;
        $hasTemplateFile = filled($type->document_url) || in_array((int) $type->getKey(), $this->withTemplateFile, true);

        if (blank($type->source)) {
            $problems[] = 'Manca la fonte (cliente, api, template, stampa).';
        } elseif (! array_key_exists($type->source, DocumentType::SOURCES)) {
            $problems[] = "Fonte «{$type->source}» sconosciuta.";
        }

        if ($type->source === DocumentType::SOURCE_API && blank($type->api_service)) {
            $problems[] = 'Fonte API senza servizio API.';
        }

        if (filled($type->api_service) && $services !== [] && ! array_key_exists($type->api_service, $services)) {
            $problems[] = "Servizio API «{$type->api_service}» non registrato.";
        }

        if (filled($type->api_service) && $type->source !== DocumentType::SOURCE_API) {
            $problems[] = 'Ha un servizio API ma la fonte non è «api».';
        }

        if ($type->source === DocumentType::SOURCE_STAMPA && ($module === null || ! $module->is_active)) {
            $problems[] = 'Fonte stampa senza modulo PDF attivo.';
        }

        if ($module?->is_active && $type->source !== DocumentType::SOURCE_STAMPA) {
            $problems[] = 'Ha un modulo PDF attivo ma la fonte non è «stampa».';
        }

        if ($type->source === DocumentType::SOURCE_TEMPLATE && ! $hasTemplateFile) {
            $problems[] = 'Fonte template senza URL né file del template.';
        }

        if ($type->is_signed && blank($type->signer_model)) {
            $problems[] = 'Da firmare senza chi firma.';
        }

        foreach (['signer_model' => 'chi firma', 'approver_model' => 'chi approva'] as $column => $label) {
            $alias = $type->{$column};

            if (filled($alias) && ! isset(MorphMap::all()[$alias]) && Relation::getMorphedModel($alias) === null) {
                $problems[] = "Alias di {$label} «{$alias}» non presente nel morph map.";
            }
        }

        if (filled($type->approver_model) && ! $type->is_signed) {
            $problems[] = 'Prevede l\'approvazione ma non la firma.';
        }

        if ($type->is_monitored && (int) $type->duration <= 0) {
            $problems[] = 'Scadenza monitorata senza durata: non si calcola expires_at.';
        }

        if ((int) $type->duration > 0 && ! $type->is_monitored) {
            $problems[] = 'Ha una durata ma la scadenza non è monitorata.';
        }

        if (filled($type->duration_unit) && ! in_array($type->duration_unit, self::DURATION_UNITS, true)) {
            $problems[] = "Unità di durata «{$type->duration_unit}» non valida.";
        }

        if (filled($type->client_scope) && ! array_key_exists($type->client_scope, DocumentType::CLIENT_SCOPES)) {
            $problems[] = "Ambito cliente «{$type->client_scope}» non valido.";
        }

        if (filled($type->renewed_by_id) && ((int) $type->renewed_by_id === (int) $type->getKey() || ($ids !== [] && ! in_array((int) $type->renewed_by_id, $ids, false)))) {
            $problems[] = 'Rinnovato da un tipo inesistente o da sé stesso.';
        }

        if (filled($type->clienti_id) && $banche !== [] && ! in_array((int) $type->clienti_id, $banche, false)) {
            $problems[] = 'Legato a un istituto inesistente.';
        }

        if (blank($type->code)) {
            $problems[] = 'Manca il codice.';
        }

        return $problems;
    }
}
