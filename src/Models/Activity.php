<?php

namespace Unico\Core\Models;

use Spatie\Activitylog\Models\Activity as SpatieActivity;
use Unico\Core\Concerns\UsesCoreConnection;

/**
 * Registro attività sul database core (tabella `activity_log` del pacchetto). Le app il cui database predefinito non è il core
 * (es. unicobpm in sviluppo) lo indicano in `config/activitylog.php` come `activity_model`.
 */
class Activity extends SpatieActivity
{
    use UsesCoreConnection;
}
