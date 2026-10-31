<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\Language;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;

/** Lingua della richiesta: quella scelta dall'utente, altrimenti la preferita del browser fra le disponibili. */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Security $security, private readonly LocaleSwitcher $switcher)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Dopo il firewall (8), che carica l'utente dalla sessione
        return [KernelEvents::REQUEST => ['onRequest', 4]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $user = $this->security->getUser();
        $locale = $user instanceof User ? $user->getLocale() : null;
        $locale ??= $event->getRequest()->getPreferredLanguage(array_keys(Language::LANGUAGES)) ?? Language::DEFAULT;
        // Traduttore e \Locale::getDefault() dal LocaleSwitcher; la richiesta (app.request.locale) a parte
        $this->switcher->setLocale($locale);
        $event->getRequest()->setLocale($locale);
    }
}
