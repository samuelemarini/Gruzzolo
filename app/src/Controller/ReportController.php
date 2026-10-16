<?php

namespace App\Controller;

use App\Util\Param;
use App\Service\ChartBuilder;
use App\Service\ReportService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController extends AppController
{
    #[Route('/report', name: 'report_index')]
    public function index(Request $request, ReportService $reports, ChartBuilder $charts): Response
    {
        $user = $this->me();
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

        $byCategory = $reports->byCategory($user, $from, $to, $kind);
        $summary = $reports->summary($user, $from, $to);
        $trend = $reports->monthly($user, $trendFrom, $to);
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
            'biggest' => $reports->biggestExpense($user, $from, $to),
            'tags' => $reports->byTag($user, $from, $to),
        ]);
    }
}
