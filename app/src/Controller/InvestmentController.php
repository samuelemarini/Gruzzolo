<?php

namespace App\Controller;

use App\Util\Param;
use App\Entity\Holding;
use App\Entity\InvestmentEntry;
use App\Entity\Movement;
use App\Repository\AccountRepository;
use App\Repository\HoldingRepository;
use App\Repository\InvestmentEntryRepository;
use App\Service\ChartBuilder;
use App\Service\InvestmentLive;
use App\Service\InvestmentStats;
use App\Service\Palette;
use App\Util\Money;
use App\Util\Trans;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/investimenti')]
final class InvestmentController extends AppController
{
    public function __construct(
        private readonly HoldingRepository $holdings,
        private readonly InvestmentEntryRepository $entries,
        private readonly AccountRepository $accounts,
        private readonly InvestmentStats $stats,
        private readonly InvestmentLive $live,
        private readonly ChartBuilder $charts,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'investment_index', methods: ['GET'])]
    public function index(): Response
    {
        $all = $this->holdings->findForUser($this->me(), true);
        $active = array_values(array_filter($all, fn (Holding $h) => !$h->isArchived()));
        $portfolio = $this->stats->portfolio($active);
        $timeline = array_slice($portfolio['timeline'], -24);

        return $this->render('investment/index.html.twig', [
            'portfolio' => $portfolio,
            // Stima di oggi dai prezzi di mercato (null se non disponibili).
            'live' => $this->me()->isLiveInvestments() ? $this->live->portfolio($portfolio) : null,
            'liveEnabled' => $this->me()->isLiveInvestments(),
            'archived' => array_filter($all, fn (Holding $h) => $h->isArchived()),
            'timeline' => $timeline,
            'chart' => $this->charts->lines([
                'value' => array_column($timeline, 'value'),
                'invested' => array_column($timeline, 'invested'),
            ], 320, 160),
        ]);
    }

    /** Attiva o disattiva la stima di oggi dai prezzi di mercato (impostazione dell'utente). */
    #[Route('/stima', name: 'investment_live_toggle', methods: ['POST'])]
    public function toggleLive(Request $request): Response
    {
        $this->checkCsrf($request, 'investment-live');
        $user = $this->me();
        $user->setLiveInvestments(!$user->isLiveInvestments());
        $this->em->flush();
        $this->addFlash('success', $user->isLiveInvestments() ? $this->trans('Stima di oggi attivata') : $this->trans('Stima di oggi disattivata: si vedono i valori registrati'));

        return $this->redirectToRoute('investment_index', [], 303);
    }

    #[Route('/{id}', name: 'investment_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $holding = $this->own($this->holdings->find($id));
        $stats = $this->stats->holding($holding);
        $chrono = array_reverse(array_slice($stats['entries'], 0, 24));

        return $this->render('investment/show.html.twig', [
            'holding' => $holding,
            'stats' => $stats,
            'live' => $holding->isArchived() || !$this->me()->isLiveInvestments() ? null : $this->live->estimate($holding, $stats),
            'chrono' => $chrono,
            'chart' => $this->charts->lines([
                'value' => array_map(fn ($r) => (float) $r['entry']->getValue(), $chrono),
                'invested' => array_column($chrono, 'invested'),
            ], 320, 160),
        ]);
    }

    #[Route('/nuovo', name: 'investment_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->holdingForm($request, new Holding($this->me()));
    }

    #[Route('/{id}/modifica', name: 'investment_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        return $this->holdingForm($request, $this->own($this->holdings->find($id)));
    }

    #[Route('/{id}/elimina', name: 'investment_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $holding = $this->own($this->holdings->find($id));
        $this->checkCsrf($request, 'delete-holding-'.$id);
        foreach ($holding->getEntries() as $entry) {
            if ($entry->getMovement()) {
                $this->em->remove($entry->getMovement());
            }
        }
        $this->em->remove($holding);
        $this->em->flush();
        $this->addFlash('success', $this->trans('Titolo eliminato'));

        return $this->redirectToRoute('investment_index', [], 303);
    }

    #[Route('/{id}/aggiorna', name: 'investment_entry_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function newEntry(Request $request, int $id): Response
    {
        $holding = $this->own($this->holdings->find($id));

        return $this->entryForm($request, new InvestmentEntry($holding));
    }

    #[Route('/voce/{id}', name: 'investment_entry_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editEntry(Request $request, int $id): Response
    {
        $entry = $this->entries->find($id);
        $this->own($entry?->getHolding());

        return $this->entryForm($request, $entry);
    }

    #[Route('/voce/{id}/elimina', name: 'investment_entry_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteEntry(Request $request, int $id): Response
    {
        $entry = $this->entries->find($id);
        $holding = $this->own($entry?->getHolding());
        $this->checkCsrf($request, 'delete-entry-'.$id);
        if ($entry->getMovement()) {
            $this->em->remove($entry->getMovement());
        }
        $this->em->remove($entry);
        $this->em->flush();
        $this->addFlash('success', $this->trans('Aggiornamento eliminato'));

        return $this->redirectToRoute('investment_show', ['id' => $holding->getId()], 303);
    }

    private function holdingForm(Request $request, Holding $holding): Response
    {
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'holding');
            $data = $request->request;
            $holding->setName((string) $data->get('name'))->setSymbol((string) $data->get('symbol'))
                ->setQuoteSymbol((string) $data->get('quote_symbol'))
                ->setKind((string) $data->get('kind'))->setNote((string) $data->get('note'))
                ->setArchived($data->getBoolean('archived'))->setExcludedFromReturns($data->getBoolean('excluded'));
            $color = (string) $data->get('color');
            $holding->setColor(array_key_exists($color, Palette::COLORS) ? $color : '#7FD6FF');
            if ($holding->getName() === '') {
                $errors['name'] = $this->trans('Dai un nome al titolo');
            }
            if (!$errors) {
                $isNew = $holding->getId() === null;
                $this->em->persist($holding);
                $this->em->flush();
                $this->addFlash('success', $this->trans('Titolo salvato'));

                return $isNew
                    ? $this->redirectToRoute('investment_entry_new', ['id' => $holding->getId()], 303)
                    : $this->redirectToRoute('investment_show', ['id' => $holding->getId()], 303);
            }
        }

        return $this->render('investment/holding_form.html.twig', ['holding' => $holding, 'errors' => $errors, 'kinds' => Trans::labels(Holding::KINDS)],
            new Response(status: $errors ? 422 : 200));
    }

    private function entryForm(Request $request, InvestmentEntry $entry): Response
    {
        $user = $this->me();
        $holding = $entry->getHolding();
        $errors = [];
        $mode = (float) $entry->getContribution() < 0 ? 'withdraw' : ((float) $entry->getContribution() > 0 || $entry->getId() === null ? 'deposit' : 'value');
        $accountId = $entry->getMovement()?->getAccount()->getId();

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'entry');
            $data = $request->request;
            $mode = in_array($data->get('mode'), ['deposit', 'withdraw', 'value'], true) ? (string) $data->get('mode') : 'deposit';
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $data->get('date'));
            $date ? $entry->setDate($date) : $errors['date'] = $this->trans('Data non valida');
            $amount = $mode === 'value' ? '0.00' : Money::parseLocal($data->get('amount'));
            if ($amount === null || ($mode !== 'value' && (float) $amount <= 0)) {
                $errors['amount'] = $mode === 'withdraw' ? $this->trans('Indica quanto hai ritirato') : $this->trans('Indica quanto hai versato');
            }
            $value = Money::parseLocal($data->get('value'));
            if ($value === null || (float) $value < 0) {
                $errors['value'] = $this->trans('Indica quanto vale il titolo adesso');
            }
            $entry->setNote((string) $data->get('note'));
            $accountId = Param::int($data, 'account') ?: null;
            $account = $accountId ? $this->accounts->find($accountId) : null;
            if ($account && !$account->isAccessibleBy($user)) {
                $account = null;
            }

            if (!$errors) {
                $entry->setContribution($mode === 'withdraw' ? '-'.$amount : $amount)->setValue($value);
                $this->syncMovement($entry, $mode === 'deposit' ? $account : null);
                $this->em->persist($entry);
                $this->em->flush();
                $this->addFlash('success', $this->trans('Investimento aggiornato'));

                return $this->redirectToRoute('investment_show', ['id' => $holding->getId()], 303);
            }
        }

        $stats = $this->stats->holding($holding);

        return $this->render('investment/entry_form.html.twig', [
            'entry' => $entry,
            'holding' => $holding,
            'mode' => $mode,
            'errors' => $errors,
            'accounts' => $this->accounts->findForUser($user),
            'accountId' => $accountId,
            'last' => $stats['entries'][0] ?? null,
            'stats' => $stats,
        ], new Response(status: $errors ? 422 : 200));
    }

    /** Il versamento esce dal conto scelto come giroconto verso gli investimenti (non e' una spesa). */
    private function syncMovement(InvestmentEntry $entry, ?\App\Entity\Account $account): void
    {
        $movement = $entry->getMovement();
        if (!$account) {
            if ($movement) {
                $entry->setMovement(null);
                $this->em->remove($movement);
            }

            return;
        }
        if (!$movement) {
            $movement = new Movement($this->me(), $account);
            $entry->setMovement($movement);
            $this->em->persist($movement);
        }
        $movement->setKind(Movement::TRANSFER)->setAccount($account)->setToAccount(null)
            ->setAmount($entry->getContribution())->setDate($entry->getDate())
            ->setDescription($this->trans('Versamento %name%', ['%name%' => $entry->getHolding()->getName()]));
    }
}
