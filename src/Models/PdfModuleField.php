<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Mappatura campo AcroForm -> chiave dati (ModuleSourceKey) per ogni modulo PDF. */
class PdfModuleField extends Model
{
    use UsesCoreConnection;

    protected $table = 'pdf_module_fields';

    protected $guarded = ['id'];

    public function pdfModule(): BelongsTo
    {
        return $this->belongsTo(PdfModule::class, 'pdf_module_id');
    }
}
