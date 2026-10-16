<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Movement;
use App\Model\MovementFilter;
use App\Repository\AccountRepository;
use App\Repository\MovementRepository;
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
    public function index(): Response
    {
        $user = $this->me();
        $list = $this->accounts->findForUser($user, true);

        return $this->render('account/index.html.twig', [
            'accounts' => $list,
            'balances' => $this->balances->balances($user),
            'total' => $this->balances->total($user, $list),
        ]);
    }

    #[Route('/{id}', name: 'account_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, MovementRepository $movements, ChartBuilder $charts): Response
    {
        $account = $this->own($this->accounts->find($id));
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
    public function new(Request $request): Response
    {
        $account = new Account($this->me());

        return $this->form($request, $account);
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

    private function form(Request $request, Account $account): Response
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
                $this->em->flush();
                $this->addFlash('success', 'Conto salvato');

                return $this->redirectToRoute('account_show', ['id' => $account->getId()], 303);
            }
        }

        return $this->render('account/form.html.twig', ['account' => $account, 'errors' => $errors, 'kinds' => Account::KINDS],
            new Response(status: $errors ? 422 : 200));
    }
}
