<?php

namespace App\Controller;

use App\Repository\AccountRepository;
use App\Repository\HoldingRepository;
use App\Repository\MovementRepository;
use App\Service\BalanceService;
use App\Service\InvestmentStats;
use App\Service\ReportService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AppController
{
    #[Route('/', name: 'app_home')]
    public function index(
        Request $request,
        ReportService $reports,
        MovementRepository $movements,
        AccountRepository $accounts,
        HoldingRepository $holdings,
        BalanceService $balances,
        InvestmentStats $investments,
    ): Response {
        $user = $this->me();
        $month = self::month($request);
        $from = $month;
        $to = $month->modify('last day of this month');

        // I conti esclusi dalla home restano nell'elenco col saldo, ma i loro movimenti non entrano nei
        // totali del mese ne' negli ultimi movimenti. Gli archiviati non sono in elenco ma contano, se non esclusi.
        $all = $accounts->findForUser($user, true);
        $onHome = array_filter($all, fn ($a) => $a->isOnHomeFor($user));
        $accountIds = count($onHome) === count($all) ? null : (array_values(array_map(fn ($a) => $a->getId(), $onHome)) ?: [0]);
        $accountList = array_values(array_filter($all, fn ($a) => !$a->isArchived()));

        $byCategory = $reports->byCategory($user, $from, $to, 'expense', $accountIds ?? []);
        $split = array_slice($byCategory, 0, 3);
        $rest = array_slice($byCategory, 3);
        if ($rest) {
            $split[] = ['name' => 'Altro', 'color' => '#5A5E5B', 'total' => array_sum(array_column($rest, 'total')),
                'pct' => array_sum(array_column($rest, 'pct')), 'icon' => 'circle-ellipsis', 'category' => null];
        }

        $holdingList = $holdings->findForUser($user);

        return $this->render('home/index.html.twig', [
            'month' => $month,
            'isCurrentMonth' => $month->format('Y-m') === date('Y-m'),
            'summary' => $reports->summary($user, $from, $to, $accountIds ?? []),
            'split' => $split,
            'latest' => $movements->latest($user, 6, $accountIds),
            // Per i link a Movimenti e Report: gli stessi conti dei totali della home.
            'scope' => ['account' => $accountIds ?? 'all'],
            'scopeIds' => $accountIds ?? array_map(fn ($a) => $a->getId(), $all),
            'accounts' => $accountList,
            'balances' => $balances->balances($user),
            'portfolio' => $holdingList ? $investments->portfolio($holdingList) : null,
        ]);
    }

    #[Route('/altro', name: 'more_index')]
    public function more(): Response
    {
        return $this->render('more/index.html.twig');
    }

    #[Route('/manifest.webmanifest', name: 'pwa_manifest')]
    public function manifest(): Response
    {
        $response = $this->render('pwa/manifest.json.twig');
        $response->headers->set('Content-Type', 'application/manifest+json');

        return $response;
    }

    /** Mese richiesto (?month=2026-10), primo giorno; default il mese corrente. */
    public static function month(Request $request): \DateTimeImmutable
    {
        $month = \DateTimeImmutable::createFromFormat('!Y-m', (string) $request->query->get('month', ''));

        return ($month ?: new \DateTimeImmutable('first day of this month'))->setTime(0, 0);
    }
}
