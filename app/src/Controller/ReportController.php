<?php

namespace App\Controller;

use App\Repository\AccountRepository;
use App\Repository\HoldingRepository;
use App\Util\Param;
use App\Service\ChartBuilder;
use App\Service\ReportService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController extends AppController
{
    #[Route('/report', name: 'report_index')]
    public function index(Request $request, ReportService $reports, ChartBuilder $charts, AccountRepository $accountRepository, HoldingRepository $holdingRepository): Response
    {
        $user = $this->me();
        // Conti considerati, dalla query: id dei conti, "all" per tutti, "inv" per gli investimenti
        // (come un conto in piu'). Senza parametro solo il conto principale.
        $raw = $request->query->all()['account'] ?? null;
        $chosen = $raw !== null && $raw !== '' && $raw !== [];
        $tokens = $chosen ? array_map('strval', (array) $raw) : [];
        $investments = in_array('inv', $tokens, true);
        $all = in_array('all', $tokens, true);
        $ids = array_values(array_unique(array_filter(array_map('intval', $tokens))));
        if (!$chosen) {
            $default = $accountRepository->findDefault($user)?->getId();
            $default ? $ids = [$default] : $all = true;
        } elseif (!$all && !$ids && !$investments) {
            $all = true;
        }
        if ($all) {
            $ids = [];
        }
        // Per i servizi: [] = tutti i conti, [0] = nessun conto (solo investimenti).
        $accounts = $all ? [] : ($ids ?: [0]);
        $today = new \DateTimeImmutable('today');
        $period = (string) $request->query->get('period', 'month');
        $kind = $request->query->get('kind') === 'income' ? 'income' : 'expense';

        switch ($period) {
            case 'year':
                $year = Param::int($request->query, 'year', (int) $today->format('Y'));
                $from = $today->setDate($year, 1, 1);
                $to = $today->setDate($year, 12, 31);
                $trendFrom = $from;
                $label = (string) $year;
                break;
            case 'custom':
                $from = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('from')) ?: $today->modify('first day of this month');
                $to = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('to')) ?: $today;
                if ($to < $from) {
                    [$from, $to] = [$to, $from];
                }
                $trendFrom = $from;
                $label = null;
                break;
            default:
                $period = 'month';
                $from = HomeController::month($request);
                $to = $from->modify('last day of this month');
                $trendFrom = $from->modify('-5 months');
                $label = null;
        }

        $byCategory = $reports->byCategory($user, $from, $to, $kind, $accounts, $investments);
        $summary = $reports->summary($user, $from, $to, $accounts, $investments);
        $trend = $reports->monthly($user, $trendFrom, $to, $accounts, $investments);
        foreach ($trend as &$row) {
            $row['partial'] = $row['month']->format('Y-m') === $today->format('Y-m');
        }
        unset($row);

        // Giorni trascorsi nel periodo: per il mese in corso la media si fa fino a oggi.
        $lastDay = $to > $today ? $today : $to;
        $days = $lastDay >= $from ? (int) $from->diff($lastDay)->days + 1 : 0;
        $savedTrend = array_sum(array_map(fn ($r) => $r['income'] - $r['expense'], $trend));

        return $this->render('report/index.html.twig', [
            'period' => $period,
            'kind' => $kind,
            'from' => $from,
            'to' => $to,
            'label' => $label,
            'summary' => $summary,
            'byCategory' => $byCategory,
            'donut' => $charts->donut(array_map(fn ($g) => ['value' => $g['total'], 'color' => $g['color'], 'label' => $g['name']], $byCategory)),
            'bars' => $charts->bars($trend, ['income', 'expense']),
            'savedTrend' => $savedTrend,
            'days' => $days,
            'dailyAverage' => $days > 0 ? $summary['expense'] / $days : null,
            'biggest' => $reports->biggestExpense($user, $from, $to, $accounts),
            'tags' => $reports->byTag($user, $from, $to, $accounts),
            'chips' => self::chips($accountRepository->findForUser($user, true), $holdingRepository->findForUser($user) !== [], $ids, $all, $investments),
            'accountParam' => $chosen ? self::tokens($ids, $all, $investments) : null,
            // I link alla lista movimenti portano gli stessi conti (la lista non ha gli investimenti).
            'movementAccounts' => $all || !$ids ? 'all' : $ids,
            'investments' => $investments ? $reports->investmentPeriod($user, $from, $to) : null,
        ]);
    }

    /**
     * Chip dei conti: ognuno porta alla selezione con quel conto aggiunto o tolto.
     *
     * @param list<\App\Entity\Account> $accounts
     * @param list<int> $ids
     *
     * @return list<array{label: string, color: ?string, icon: ?string, active: bool, param: list<string>}>
     */
    private static function chips(array $accounts, bool $hasHoldings, array $ids, bool $all, bool $investments): array
    {
        $chips = [['label' => 'Tutti i conti', 'color' => null, 'icon' => null, 'active' => $all, 'param' => self::tokens([], true, $investments)]];
        foreach ($accounts as $account) {
            $id = $account->getId();
            if ($account->isArchived() && !in_array($id, $ids, true)) {
                continue;
            }
            $active = !$all && in_array($id, $ids, true);
            $next = $all ? [$id] : ($active ? array_values(array_diff($ids, [$id])) : [...$ids, $id]);
            $chips[] = ['label' => $account->getName(), 'color' => $account->getColor(), 'icon' => null, 'active' => $active,
                'param' => self::tokens($next, !$next && !$investments, $investments)];
        }
        if ($hasHoldings) {
            $chips[] = ['label' => 'Investimenti', 'color' => null, 'icon' => 'trending-up', 'active' => $investments,
                'param' => self::tokens($ids, $all || (!$ids && $investments), !$investments)];
        }

        return $chips;
    }

    /** @return list<string> valori del parametro "account" */
    private static function tokens(array $ids, bool $all, bool $investments): array
    {
        $tokens = $all ? ['all'] : array_map('strval', $ids);
        if ($investments) {
            $tokens[] = 'inv';
        }

        return $tokens ?: ['all'];
    }
}
