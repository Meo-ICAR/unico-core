<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Unico\Core\Concerns\UsesCoreConnection;
use Unico\Core\Pdf\PdfModuleClientScope;

/** Moduli PDF compilabili (AcroForm) presenti in storage/app/public/module. */
class PdfModule extends Model
{
    use UsesCoreConnection;

    protected $table = 'pdf_modules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tipi_prodotto' => 'array',
            'is_active' => 'boolean',
            'signature_slots' => 'array',
        ];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function pdfModuleFields(): HasMany
    {
        return $this->hasMany(PdfModuleField::class, 'pdf_module_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Il modulo è pertinente se il prodotto è tra quelli indicati (o l'elenco è vuoto)
     * e il tipo di cliente rientra nell'ambito del modulo.
     */
    public function appliesTo(?string $tipoProdotto, bool $isPerson): bool
    {
        $tipi = array_map('mb_strtolower', $this->tipi_prodotto ?? []);

        $productMatches = $tipi === []
            || ($tipoProdotto !== null && in_array(mb_strtolower($tipoProdotto), $tipi, true));

        return $productMatches && $this->clientScope()->matches($isPerson);
    }

    private function clientScope(): PdfModuleClientScope
    {
        $scope = $this->client_scope;

        if ($scope instanceof PdfModuleClientScope) {
            return $scope;
        }

        $value = $scope instanceof \BackedEnum ? $scope->value : $scope;

        return PdfModuleClientScope::tryFrom((string) $value) ?? PdfModuleClientScope::Entrambi;
    }
}
