<?php

namespace Unico\Core\Tests\Support;

/** Sostituisce il gestore di autenticazione: serve solo l'id dell'utente corrente. */
class FakeAuth
{
    public ?int $userId = null;

    public function id(): ?int
    {
        return $this->userId;
    }

    public function user(): ?object
    {
        return null;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return null;
    }
}
