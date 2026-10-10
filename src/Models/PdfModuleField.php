<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesCoreConnection;
use Unico\Core\Pdf\ModuleFormatter;

/** Mappatura campo AcroForm -> chiave dati (stringa, es. `client.name`) per ogni modulo PDF. */
class PdfModuleField extends Model
{
    use UsesCoreConnection;

    protected $table = 'pdf_module_fields';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['formatter' => ModuleFormatter::class];
    }

    public function pdfModule(): BelongsTo
    {
        return $this->belongsTo(PdfModule::class, 'pdf_module_id');
    }

    public function isCheckbox(): bool
    {
        return $this->pdf_field_type === 'checkbox';
    }

    /**
     * Le chiavi dati da cui dipende la compilazione del campo.
     *
     * @return array<int, string>
     */
    public function referencedKeys(): array
    {
        $keys = [];

        if (filled($this->source_key)) {
            $keys[] = $this->source_key;
        }

        if (filled($this->checkbox_when)) {
            $when = $this->parseCheckboxWhen()['key'];

            if ($when !== null) {
                $keys[] = $when;
            }
        }

        return $keys;
    }

    /**
     * Interpreta `checkbox_when` nel formato `[!]chiave[=valore]`.
     *
     * @return array{negate: bool, key: string|null, value: string|null}
     */
    public function parseCheckboxWhen(): array
    {
        $when = (string) $this->checkbox_when;
        $negate = str_starts_with($when, '!');
        [$name, $value] = array_pad(explode('=', ltrim($when, '!'), 2), 2, null);

        return ['negate' => $negate, 'key' => filled($name) ? $name : null, 'value' => $value];
    }
}
