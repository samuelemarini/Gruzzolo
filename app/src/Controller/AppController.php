<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Movement;
use App\Entity\OwnedInterface;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Base dei controller dell'app: utente corrente e controllo di proprieta' dei dati. */
abstract class AppController extends AbstractController
{
    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices() + ['translator' => TranslatorInterface::class];
    }

    /** Testo nella lingua dell'utente: la frase italiana e' la chiave. */
    protected function trans(string $id, array $params = []): string
    {
        return $this->container->get('translator')->trans($id, $params);
    }

    protected function me(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * I dati di un altro utente sono "inesistenti": 404, non 403, per non rivelarne l'esistenza.
     *
     * @template T of OwnedInterface
     *
     * @param T|null $entity
     *
     * @return T
     */
    protected function own(?OwnedInterface $entity): OwnedInterface
    {
        if ($entity === null || $entity->getUser() !== $this->me()) {
            throw $this->createNotFoundException();
        }

        return $entity;
    }

    /** Conto proprio o condiviso con l'utente (404 altrimenti). */
    protected function accessibleAccount(?Account $account): Account
    {
        if ($account === null || !$account->isAccessibleBy($this->me())) {
            throw $this->createNotFoundException();
        }

        return $account;
    }

    /** Movimento proprio o che sta su un conto condiviso con l'utente. */
    protected function accessibleMovement(?Movement $movement): Movement
    {
        $me = $this->me();
        if ($movement === null || !($movement->getUser() === $me || $movement->getAccount()->isAccessibleBy($me)
            || $movement->getToAccount()?->isAccessibleBy($me))) {
            throw $this->createNotFoundException();
        }

        return $movement;
    }

    protected function checkCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Sessione scaduta: ricarica la pagina e riprova.');
        }
    }

    /** Torna dove si era (parametro "back" o Referer interno), altrimenti alla rotta indicata. */
    protected function back(Request $request, string $fallbackRoute, array $params = []): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $back = (string) ($request->request->get('_back') ?: $request->query->get('back', ''));
        if ($back !== '' && str_starts_with($back, '/') && !str_starts_with($back, '//')) {
            return $this->redirect($back, 303);
        }

        return $this->redirectToRoute($fallbackRoute, $params, 303);
    }
}
