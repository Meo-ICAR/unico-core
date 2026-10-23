<?php

namespace Unico\Core\Clients;

use RuntimeException;

/** Il servizio di simulazione non ha dato una risposta utilizzabile (non raggiungibile, errore HTTP o XML non valido). */
class SimulationUnavailable extends RuntimeException {}
