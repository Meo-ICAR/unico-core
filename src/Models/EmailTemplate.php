<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Unico\Core\Concerns\UsesCoreConnection;

/** Registro dei template email configurabili. */
class EmailTemplate extends Model
{
    use UsesCoreConnection;

    protected $table = 'email_templates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'placeholders' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
