<?php

namespace Unico\Core\Clients;

use RuntimeException;

/** Il servizio di simulazione non è raggiungibile, ha risposto con un errore o con una risposta illeggibile. */
class SimulationUnavailable extends RuntimeException {}
