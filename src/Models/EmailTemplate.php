<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Registro dei template email configurabili. */
class EmailTemplate extends Model
{
    use UsesCoreConnection;

    protected $table = 'email_templates';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Con company e app nulle MySQL non garantisce l'unicità dell'indice: per ogni «ambito» (company, app, severity) un solo
        // template per codice.
        static::saving(function (self $template): void {
            $duplicate = static::query()
                ->where('code', $template->code)
                ->when($template->severity === null, fn ($q) => $q->whereNull('severity'), fn ($q) => $q->where('severity', $template->severity))
                ->when($template->company_id === null, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', $template->company_id))
                ->when($template->app_identifier === null, fn ($q) => $q->whereNull('app_identifier'), fn ($q) => $q->where('app_identifier', $template->app_identifier))
                ->when($template->exists, fn ($q) => $q->whereKeyNot($template->getKey()))
                ->exists();

            if ($duplicate) {
                throw new \InvalidArgumentException("Esiste già un template «{$template->code}» per questo grado, company e app.");
            }
        });
    }

    protected function casts(): array
    {
        return [
            'placeholders' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** Company a cui è riservato il template; nulla = comune a tutte. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
