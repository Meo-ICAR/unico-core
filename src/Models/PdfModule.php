<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Unico\Core\Concerns\UsesCoreConnection;

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
}
