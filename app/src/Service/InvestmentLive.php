<?php

namespace App\Service;

use App\Entity\Holding;

/**
 * Valore stimato oggi dei titoli, senza bisogno di sapere quante quote si hanno: l'ultimo valore
 * registrato a mano si moltiplica per quanto e' cambiato il prezzo di mercato da quel giorno.
 * Esempio: 2.564 € il 2 ottobre, prezzo +1,8% da allora → 2.610 € stimati oggi.
 */
final class InvestmentLive
{
    public function __construct(private readonly MarketPrices $prices)
    {
    }

    /**
     * @param array{value: float, invested: float, lastDate: ?\DateTimeImmutable} $stats dati del titolo (InvestmentStats::holding)
     *
     * @return array{value: float, change: float, changePct: float, gain: float, pct: ?float, symbol: string, time: \DateTimeImmutable}|null
     */
    public function estimate(Holding $holding, array $stats): ?array
    {
        if (!$stats['lastDate'] || $stats['value'] <= 0 || !($symbol = $this->prices->resolve($holding))) {
            return null;
        }
        $quote = $this->prices->quote($symbol, $stats['lastDate']);
        $base = $quote ? MarketPrices::onOrBefore($quote['closes'], $stats['lastDate']->format('Y-m-d')) : null;
        if (!$base || $base <= 0) {
            return null;
        }
        $value = round($stats['value'] * $quote['price'] / $base, 2);
        $gain = $value - $stats['invested'];

        return [
            'value' => $value,
            'change' => round($value - $stats['value'], 2),
            'changePct' => ($quote['price'] / $base - 1) * 100,
            'gain' => round($gain, 2),
            'pct' => $stats['invested'] > 0 ? $gain / $stats['invested'] * 100 : null,
            'symbol' => $symbol,
            'time' => $quote['time'],
        ];
    }

    /**
     * Stima del portafoglio: i titoli senza prezzo contano col loro ultimo valore registrato, quelli
     * esclusi dal rendimento hanno la loro stima ma restano fuori dal totale.
     *
     * @param array{invested: float, value: float, holdings: list<array{holding: Holding, stats: array}>} $portfolio
     *
     * @return array{value: float, change: float, changePct: ?float, gain: float, pct: ?float, items: array<int, array>, time: ?\DateTimeImmutable, count: int}|null
     */
    public function portfolio(array $portfolio): ?array
    {
        $items = [];
        $value = 0.0;
        $time = null;
        foreach ($portfolio['holdings'] as $item) {
            $estimate = $this->estimate($item['holding'], $item['stats']);
            if (!$item['holding']->isExcludedFromReturns()) {
                $value += $estimate['value'] ?? $item['stats']['value'];
            }
            if ($estimate) {
                $items[$item['holding']->getId()] = $estimate;
                $time = !$time || $estimate['time'] > $time ? $estimate['time'] : $time;
            }
        }
        if (!$items) {
            return null;
        }
        $gain = $value - $portfolio['invested'];

        return [
            'value' => round($value, 2),
            'change' => round($value - $portfolio['value'], 2),
            'changePct' => $portfolio['value'] > 0 ? ($value / $portfolio['value'] - 1) * 100 : null,
            'gain' => round($gain, 2),
            'pct' => $portfolio['invested'] > 0 ? $gain / $portfolio['invested'] * 100 : null,
            'items' => $items,
            'time' => $time,
            'count' => count($items),
        ];
    }
}
