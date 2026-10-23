<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Unico\Core\Concerns\UsesCoreConnection;

/** Un'esecuzione dell'import storico Races: l'import incrementale riparte dall'ultima riuscita. */
class RacesImportRun extends Model
{
    use UsesCoreConnection;

    protected $table = 'races_import_runs';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
