<?php

namespace Unico\Core\Signature\Exceptions;

use DomainException;

/**
 * Errore di dominio nell'invio di una richiesta di firma; il messaggio e' mostrabile all'operatore.
 */
class SignatureRequestException extends DomainException {}
