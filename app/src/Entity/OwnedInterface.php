<?php

namespace App\Entity;

/** Ogni dato appartiene a un utente: i controller lo verificano con AppController::own(). */
interface OwnedInterface
{
    public function getUser(): User;
}
