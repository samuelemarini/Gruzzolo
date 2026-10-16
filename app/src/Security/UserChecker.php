<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Gli utenti disattivati dall'admin non entrano piu' (nemmeno col "ricordami"). */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof User && !$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('Questo utente è stato disattivato.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
