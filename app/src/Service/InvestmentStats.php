<?php

namespace App\Service;

use App\Entity\Holding;
use App\Entity\InvestmentEntry;

/** Rendimento dei titoli: valore attuale - somma dei versamenti. */
final class InvestmentStats
{
    /**
     * @return array{
     *   invested: float, value: float, gain: float, pct: ?float, lastDate: ?\DateTimeImmutable,
     *   entries: list<array{entry: InvestmentEntry, invested: float, gain: float, pct: ?float, delta: ?float}>
     * }
     */
    public function holding(Holding $holding): array
    {
        $invested = 0.0;
        $value = 0.0;
        $lastDate = null;
        $rows = [];
        $prevValue = null;
        foreach ($holding->getEntries() as $entry) {
            $invested += (float) $entry->getContribution();
            $value = (float) $entry->getValue();
            $lastDate = $entry->getDate();
            $gain = $value - $invested;
            // Quanto e' cambiato il valore da solo rispetto alla volta prima, al netto del versamento.
            $delta = $prevValue !== null ? $value - $prevValue - (float) $entry->getContribution() : null;
            $rows[] = ['entry' => $entry, 'invested' => round($invested, 2), 'gain' => round($gain, 2),
                'pct' => $invested > 0 ? $gain / $invested * 100 : null, 'delta' => $delta !== null ? round($delta, 2) : null];
            $prevValue = $value;
        }
        $gain = $value - $invested;

        return [
            'invested' => round($invested, 2), 'value' => round($value, 2), 'gain' => round($gain, 2),
            'pct' => $invested > 0 ? $gain / $invested * 100 : null, 'lastDate' => $lastDate,
            'entries' => array_reverse($rows),
        ];
    }

    /**
     * Portafoglio: totali e andamento nel tempo (per ogni data, ultimo valore noto di ogni titolo).
     *
     * @param list<Holding> $holdings
     *
     * @return array{invested: float, value: float, gain: float, pct: ?float, holdings: list<array{holding: Holding, stats: array}>, timeline: list<array{date: \DateTimeImmutable, value: float, invested: float}>}
     */
    public function portfolio(array $holdings): array
    {
        $items = [];
        $events = [];
        foreach ($holdings as $holding) {
            $stats = $this->holding($holding);
            $items[] = ['holding' => $holding, 'stats' => $stats];
            foreach ($holding->getEntries() as $entry) {
                $events[$entry->getDate()->format('Y-m-d')][] = $entry;
            }
        }
        usort($items, fn ($a, $b) => $b['stats']['value'] <=> $a['stats']['value']);
        ksort($events);

        $lastValue = [];
        $invested = [];
        $timeline = [];
        foreach ($events as $day => $entries) {
            foreach ($entries as $entry) {
                $id = $entry->getHolding()->getId();
                $lastValue[$id] = (float) $entry->getValue();
                $invested[$id] = ($invested[$id] ?? 0) + (float) $entry->getContribution();
            }
            $timeline[] = ['date' => new \DateTimeImmutable($day), 'value' => round(array_sum($lastValue), 2), 'invested' => round(array_sum($invested), 2)];
        }

        $totalInvested = array_sum(array_map(fn ($i) => $i['stats']['invested'], $items));
        $totalValue = array_sum(array_map(fn ($i) => $i['stats']['value'], $items));
        $gain = $totalValue - $totalInvested;

        return [
            'invested' => round($totalInvested, 2), 'value' => round($totalValue, 2), 'gain' => round($gain, 2),
            'pct' => $totalInvested > 0 ? $gain / $totalInvested * 100 : null,
            'holdings' => $items, 'timeline' => $timeline,
        ];
    }
}
