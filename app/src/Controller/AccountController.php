<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Movement;
use App\Model\MovementFilter;
use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use App\Repository\HoldingRepository;
use App\Service\InvestmentStats;
use App\Service\UserSeeder;
use App\Repository\MovementRepository;
use App\Repository\UserRepository;
use App\Service\BalanceService;
use App\Service\ChartBuilder;
use App\Service\Palette;
use App\Util\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/conti')]
final class AccountController extends AppController
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly BalanceService $balances,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'account_index', methods: ['GET'])]
    public function index(HoldingRepository $holdings, InvestmentStats $stats): Response
    {
        $user = $this->me();
        $list = $this->accounts->findForUser($user, true);
        $balances = $this->balances->balances($user);
        $holdingList = $holdings->findForUser($user);
        $investments = $holdingList ? $stats->portfolio($holdingList)['value'] : null;

        // Patrimonio: i conti (non archiviati) e gli investimenti che l'utente ha scelto di contare.
        $items = [];
        foreach ($list as $account) {
            if (!$account->isArchived()) {
                $key = (string) $account->getId();
                $items[] = ['key' => $key, 'label' => $account->getName(), 'color' => $account->getColor(), 'icon' => null,
                    'value' => $balances[$account->getId()] ?? 0.0, 'included' => $user->isInNetWorth($key)];
            }
        }
        if ($investments !== null) {
            $items[] = ['key' => 'inv', 'label' => 'Investimenti', 'color' => null, 'icon' => 'trending-up',
                'value' => $investments, 'included' => $user->isInNetWorth('inv')];
        }
        $included = array_filter($items, fn ($i) => $i['included']);

        return $this->render('account/index.html.twig', [
            'accounts' => $list,
            'balances' => $balances,
            'netWorth' => round(array_sum(array_column($included, 'value')), 2),
            'netWorthItems' => $items,
            'netWorthAll' => count($included) === count($items),
            'investments' => $investments,
        ]);
    }

    /** Conta o toglie una voce (conto o investimenti) dal patrimonio: la scelta resta salvata per l'utente. */
    #[Route('/patrimonio/{key}', name: 'account_net_worth', requirements: ['key' => '\d+|inv'], methods: ['POST'])]
    public function netWorth(Request $request, string $key): Response
    {
        $this->checkCsrf($request, 'net-worth');
        if ($key !== 'inv') {
            $this->accessibleAccount($this->accounts->find((int) $key));
        }
        $this->me()->toggleNetWorth($key);
        $this->em->flush();

        return $this->redirectToRoute('account_index', [], 303);
    }

    #[Route('/{id}', name: 'account_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, MovementRepository $movements, ChartBuilder $charts): Response
    {
        $account = $this->accessibleAccount($this->accounts->find($id));
        $user = $this->me();
        $filter = new MovementFilter();
        $filter->accounts = [$account->getId()];
        $filter->period = 'all';

        // Saldo a fine mese negli ultimi 12 mesi (l'ultimo punto e' oggi).
        $points = [];
        $labels = [];
        for ($i = 11; $i >= 0; --$i) {
            $day = $i === 0 ? new \DateTimeImmutable('today') : new \DateTimeImmutable("last day of -$i month");
            $points[] = $this->balances->balances($user, $day)[$account->getId()] ?? 0.0;
            $labels[] = $day;
        }

        return $this->render('account/show.html.twig', [
            'account' => $account,
            'balance' => end($points),
            'movements' => $movements->search($user, $filter, 40),
            'totals' => $movements->totals($user, $filter),
            'chart' => $charts->lines(['balance' => $points], 320, 150),
            'labels' => $labels,
        ]);
    }

    #[Route('/nuovo', name: 'account_new', methods: ['GET', 'POST'])]
    public function new(Request $request, CategoryRepository $categories, UserSeeder $seeder): Response
    {
        $account = new Account($this->me());

        return $this->form($request, $account, function (Account $account, string $source) use ($categories, $seeder) {
            // Un conto nuovo parte con categorie copiate da un altro, con quelle predefinite o vuoto.
            if ($source === 'default') {
                $seeder->seedCategories($account);
            } elseif ($source !== 'none' && ($from = $this->accounts->find((int) $source)) && $from->isAccessibleBy($this->me())) {
                $categories->copyAll($from, $account);
            }
        });
    }

    #[Route('/{id}/modifica', name: 'account_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        return $this->form($request, $this->own($this->accounts->find($id)));
    }

    #[Route('/{id}/elimina', name: 'account_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $account = $this->own($this->accounts->find($id));
        $this->checkCsrf($request, 'delete-account-'.$id);
        $repo = $this->em->getRepository(Movement::class);
        if ($repo->count(['account' => $account]) + $repo->count(['toAccount' => $account]) > 0) {
            $this->addFlash('error', 'Il conto ha dei movimenti: puoi archiviarlo, non eliminarlo');

            return $this->redirectToRoute('account_edit', ['id' => $id], 303);
        }
        $this->em->remove($account);
        $this->em->flush();
        $this->addFlash('success', 'Conto eliminato');

        return $this->redirectToRoute('account_index', [], 303);
    }

    /** Il proprietario condivide il conto con un altro utente, indicandone il nome utente. */
    #[Route('/{id}/condividi', name: 'account_share', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function share(Request $request, int $id, UserRepository $users): Response
    {
        $account = $this->own($this->accounts->find($id));
        $this->checkCsrf($request, 'account-share-'.$id);
        $other = $users->findOneByUsername((string) $request->request->get('username'));
        if (!$other || !$other->isActive()) {
            $this->addFlash('error', 'Nessun utente con questo nome');
        } elseif ($other === $this->me()) {
            $this->addFlash('error', 'Il conto è già tuo');
        } else {
            $account->addMember($other);
            $this->em->flush();
            $this->addFlash('success', sprintf('Conto condiviso con %s', $other->getDisplayName()));
        }

        return $this->redirectToRoute('account_edit', ['id' => $id], 303);
    }

    #[Route('/{id}/condividi/{userId}/togli', name: 'account_unshare', requirements: ['id' => '\d+', 'userId' => '\d+'], methods: ['POST'])]
    public function unshare(Request $request, int $id, int $userId, UserRepository $users): Response
    {
        $account = $this->own($this->accounts->find($id));
        $this->checkCsrf($request, 'account-unshare-'.$id.'-'.$userId);
        if ($other = $users->find($userId)) {
            $account->removeMember($other);
            $this->em->flush();
            $this->addFlash('success', sprintf('%s non vede più il conto', $other->getDisplayName()));
        }

        return $this->redirectToRoute('account_edit', ['id' => $id], 303);
    }

    /** Chi ha ricevuto un conto condiviso puo' uscirne: i movimenti che ha inserito restano sul conto. */
    #[Route('/{id}/esci', name: 'account_leave', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function leave(Request $request, int $id): Response
    {
        $account = $this->accessibleAccount($this->accounts->find($id));
        $this->checkCsrf($request, 'account-leave-'.$id);
        $account->removeMember($this->me());
        $this->em->flush();
        $this->addFlash('success', 'Sei uscito dal conto condiviso');

        return $this->redirectToRoute('account_index', [], 303);
    }

    /** Include o esclude i movimenti del conto dai totali della propria home (serve a chi l'ha ricevuto in condivisione: non apre la modifica). */
    #[Route('/{id}/home', name: 'account_home', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function home(Request $request, int $id): Response
    {
        $account = $this->accessibleAccount($this->accounts->find($id));
        $this->checkCsrf($request, 'account-home-'.$id);
        $account->setOnHomeFor($this->me(), $request->request->getBoolean('show'));
        $this->em->flush();
        $this->addFlash('success', $account->isOnHomeFor($this->me()) ? 'I movimenti del conto tornano nei totali della home' : 'I movimenti del conto non entrano più nei totali della home');

        return $this->redirectToRoute('account_show', ['id' => $id], 303);
    }

    private function form(Request $request, Account $account, ?callable $onCreate = null): Response
    {
        $user = $this->me();
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'account');
            $data = $request->request;
            $account->setName((string) $data->get('name'))->setKind((string) $data->get('kind'));
            $color = (string) $data->get('color');
            $account->setColor(array_key_exists($color, Palette::COLORS) ? $color : '#8AB4FF');
            $initial = Money::parse($data->get('initial_balance') ?: '0');
            $initial !== null ? $account->setInitialBalance($initial) : $errors['initial_balance'] = 'Importo non valido';
            if ($account->getName() === '') {
                $errors['name'] = 'Dai un nome al conto';
            }
            $account->setArchived($data->getBoolean('archived'));
            $account->setOnHomeFor($user, $data->getBoolean('home'));
            $makeDefault = $data->getBoolean('default') && !$account->isArchived();

            if (!$errors) {
                if ($makeDefault || !$this->accounts->findDefault($user)) {
                    foreach ($this->accounts->findBy(['user' => $user]) as $other) {
                        $other->setIsDefault(false);
                    }
                    $account->setIsDefault(true);
                } elseif ($account->isArchived()) {
                    $account->setIsDefault(false);
                }
                $this->em->persist($account);
                if ($onCreate) {
                    $onCreate($account, (string) $data->get('categories_from', 'default'));
                }
                $this->em->flush();
                $this->addFlash('success', 'Conto salvato');

                return $this->redirectToRoute('account_show', ['id' => $account->getId()], 303);
            }
        }

        return $this->render('account/form.html.twig', ['account' => $account, 'errors' => $errors, 'kinds' => Account::KINDS,
            'sources' => $this->accounts->findForUser($user), 'defaultSource' => $this->accounts->findDefault($user)],
            new Response(status: $errors ? 422 : 200));
    }
}
