<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Gli account li crea l'amministratore: niente registrazione pubblica. */
#[Route('/admin/utenti')]
#[IsGranted('ROLE_ADMIN')]
final class AdminUserController extends AppController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    #[Route('', name: 'admin_user_index', methods: ['GET'])]
    public function index(): Response
    {
        $counts = $this->em->getConnection()->fetchAllKeyValue('SELECT user_id, COUNT(*) FROM movement GROUP BY user_id');

        return $this->render('admin/index.html.twig', [
            'users' => $this->users->findBy([], ['username' => \SortDirection::Ascending]),
            'counts' => $counts,
        ]);
    }

    #[Route('/nuovo', name: 'admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request, UserSeeder $seeder): Response
    {
        return $this->form($request, new User(), $seeder);
    }

    #[Route('/{id}', name: 'admin_user_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $user = $this->users->find($id) ?? throw $this->createNotFoundException();

        return $this->form($request, $user, null);
    }

    #[Route('/{id}/elimina', name: 'admin_user_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $user = $this->users->find($id) ?? throw $this->createNotFoundException();
        $this->checkCsrf($request, 'delete-user-'.$id);
        if ($user === $this->me()) {
            $this->addFlash('error', 'Non puoi eliminare il tuo utente');

            return $this->redirectToRoute('admin_user_edit', ['id' => $id], 303);
        }
        if ($request->request->get('confirm') !== $user->getUsername()) {
            $this->addFlash('error', 'Per eliminare scrivi il nome utente esatto');

            return $this->redirectToRoute('admin_user_edit', ['id' => $id], 303);
        }
        // I dati dell'utente vanno via in cascata (ON DELETE CASCADE su tutte le tabelle).
        $this->em->getConnection()->executeStatement('DELETE FROM movement WHERE user_id = ?', [$id]);
        $this->em->remove($user);
        $this->em->flush();
        $this->addFlash('success', 'Utente e dati eliminati');

        return $this->redirectToRoute('admin_user_index', [], 303);
    }

    private function form(Request $request, User $user, ?UserSeeder $seeder): Response
    {
        $errors = [];
        $isNew = $user->getId() === null;
        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'admin-user');
            $data = $request->request;
            if ($isNew) {
                $username = mb_strtolower(trim((string) $data->get('username')));
                if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
                    $errors['username'] = 'Da 3 a 40 caratteri: lettere, numeri, punto, trattino';
                } elseif ($this->users->findOneByUsername($username)) {
                    $errors['username'] = 'Nome utente già usato';
                }
                $user->setUsername($username);
            }
            $user->setDisplayName((string) $data->get('display_name'));
            if ($user !== $this->me()) {
                $user->setAdmin($data->getBoolean('admin'))->setActive($data->getBoolean('active'));
            }
            $password = (string) $data->get('password');
            if ($isNew || $password !== '') {
                if (mb_strlen($password) < 8) {
                    $errors['password'] = 'Almeno 8 caratteri';
                } else {
                    $user->setPassword($this->hasher->hashPassword($user, $password));
                }
            }
            if (!$errors) {
                if ($isNew) {
                    $this->em->persist($user);
                    $seeder?->seed($user);
                }
                $this->em->flush();
                $this->addFlash('success', $isNew ? 'Utente creato con conto e categorie di partenza' : 'Utente aggiornato');

                return $this->redirectToRoute('admin_user_index', [], 303);
            }
        }

        return $this->render('admin/form.html.twig', ['user' => $user, 'isNew' => $isNew, 'errors' => $errors],
            new Response(status: $errors ? 422 : 200));
    }
}
