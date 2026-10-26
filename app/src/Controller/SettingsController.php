<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Palette;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/impostazioni')]
final class SettingsController extends AppController
{
    #[Route('', name: 'settings_index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        $user = $this->me();
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'settings');
            $data = $request->request;
            switch ($data->get('section')) {
                case 'theme':
                    $user->setTheme((string) $data->get('theme'));
                    $em->flush();
                    break;
                case 'style':
                    $user->setStyle((string) $data->get('style'));
                    $em->flush();
                    break;
                case 'colors':
                    $user->setAccent((string) $data->get('accent'));
                    // Staccando l'icona dall'accento parte dal colore che aveva gia'
                    $user->setIconColor($data->getBoolean('icon_follow') ? Palette::ICON_FOLLOWS_ACCENT : (string) $data->get('icon_color', $user->getIconAccent()));
                    $em->flush();
                    break;
                case 'profile':
                    $name = trim((string) $data->get('display_name'));
                    if ($name === '') {
                        $errors['display_name'] = 'Il nome non può essere vuoto';
                        break;
                    }
                    $user->setDisplayName($name);
                    $em->flush();
                    $this->addFlash('success', 'Profilo aggiornato');
                    break;
                case 'password':
                    $new = (string) $data->get('new_password');
                    if (!$hasher->isPasswordValid($user, (string) $data->get('current_password'))) {
                        $errors['current_password'] = 'La password attuale non è corretta';
                    } elseif (mb_strlen($new) < 8) {
                        $errors['new_password'] = 'Almeno 8 caratteri';
                    } elseif ($new !== $data->get('confirm_password')) {
                        $errors['confirm_password'] = 'Le due password non coincidono';
                    } else {
                        $user->setPassword($hasher->hashPassword($user, $new));
                        $em->flush();
                        $this->addFlash('success', 'Password cambiata');
                    }
                    break;
            }
            if (!$errors) {
                return $this->redirectToRoute('settings_index', [], 303);
            }
        }

        return $this->render('settings/index.html.twig', ['errors' => $errors, 'themes' => User::THEMES, 'styles' => User::STYLES],
            new Response(status: $errors ? 422 : 200));
    }
}
