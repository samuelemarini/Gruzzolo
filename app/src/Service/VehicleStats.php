<?php

namespace App\Service;

use App\Entity\Refuel;
use App\Entity\Vehicle;
use App\Repository\RefuelRepository;
use App\Util\Trans;
use Doctrine\DBAL\Connection;

/**
 * Consumi e costi di un veicolo.
 *
 * Il consumo si calcola da pieno a pieno, come Drivvo: i km fra due pieni diviso i litri messi
 * dopo il primo (rabbocchi parziali compresi). "Ho saltato un rifornimento" spezza la catena.
 */
final class VehicleStats
{
    public function __construct(
        private readonly RefuelRepository $refuels,
        private readonly Connection $db,
    ) {
    }

    /**
     * Statistiche del veicolo nel periodo [$from, $to] (estremi facoltativi), sul modello di Drivvo.
     *
     * Oltre a consumi e costi: volumi, prezzi minimo/massimo, consumo e costo al km di ogni pieno,
     * abitudini (km e giorni fra un rifornimento e l'altro, quota di pieni), autonomia stimata dal
     * serbatoio, costi mese per mese, serie per i grafici di contachilometri e prezzo.
     *
     * @return array<string, mixed>
     */
    public function compute(Vehicle $vehicle, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $all = $this->refuels->findForVehicle($vehicle);
        $rows = [];
        $lastFull = null;
        $litersSince = 0.0;
        $costSince = 0.0;
        $prevOdometer = $vehicle->getInitialOdometer();
        foreach ($all as $refuel) {
            $efficiency = null;
            $segment = null;
            $distance = $prevOdometer !== null ? $refuel->getOdometer() - $prevOdometer : null;
            if ($refuel->isMissedPrevious()) {
                $lastFull = $refuel->isFullTank() ? $refuel : null;
                $litersSince = 0.0;
                $costSince = 0.0;
            } else {
                $litersSince += (float) $refuel->getLiters();
                // Costo alla pompa (litri x prezzo): i rifornimenti pagati con buoni o a 0 € non falsano il €/km.
                $costSince += (float) $refuel->getPricePerLiter() > 0
                    ? (float) $refuel->getLiters() * (float) $refuel->getPricePerLiter()
                    : (float) $refuel->getMovement()->getAmount();
                if ($refuel->isFullTank()) {
                    if ($lastFull !== null && $litersSince > 0) {
                        $km = $refuel->getOdometer() - $lastFull->getOdometer();
                        if ($km > 0) {
                            $efficiency = $km / $litersSince;
                            // Costo del tratto: quanto si e' pagato per i litri consumati fra i due pieni.
                            $segment = [$km, $litersSince, $costSince];
                        }
                    }
                    $lastFull = $refuel;
                    $litersSince = 0.0;
                    $costSince = 0.0;
                }
            }
            $rows[] = ['refuel' => $refuel, 'efficiency' => $efficiency, 'distance' => $distance, 'segment' => $segment,
                'costPerKm' => $segment && $segment[2] > 0 ? $segment[2] / $segment[0] : null];
            $prevOdometer = $refuel->getOdometer();
        }

        $inPeriod = static fn (\DateTimeImmutable $d) => (!$from || $d >= $from) && (!$to || $d <= $to);

        // Media del periodo: km/l pesato sui segmenti che finiscono nel periodo.
        $segKm = 0.0;
        $segLiters = 0.0;
        $segCost = 0.0;
        $series = [];
        $fuelCost = 0.0;
        $liters = 0.0;
        $pumpCost = 0.0;
        $count = 0;
        $fullCount = 0;
        $minOdo = null;
        $maxOdo = null;
        $firstDate = $lastDate = null;
        $prices = [];
        $efficiencies = [];
        $costsPerKm = [];
        $odometerSeries = [];
        $priceSeries = [];
        $fuelByMonth = [];
        $lastRow = null;
        $before = $vehicle->getInitialOdometer();
        foreach ($rows as $row) {
            $refuel = $row['refuel'];
            $date = $refuel->getMovement()->getDate();
            if ($from && $date < $from) {
                $before = $refuel->getOdometer();
                continue;
            }
            if (!$inPeriod($date)) {
                continue;
            }
            ++$count;
            $amount = (float) $refuel->getMovement()->getAmount();
            $fuelCost += $amount;
            $liters += (float) $refuel->getLiters();
            // Prezzo alla pompa: da litri e €/l, non da quanto hai pagato (buoni, spese divise, rifornimenti a 0 €).
            $pumpCost += (float) $refuel->getLiters() * (float) $refuel->getPricePerLiter();
            $fullCount += $refuel->isFullTank() ? 1 : 0;
            $minOdo = $minOdo === null ? $refuel->getOdometer() : min($minOdo, $refuel->getOdometer());
            $maxOdo = max($maxOdo ?? 0, $refuel->getOdometer());
            $firstDate ??= $date;
            $lastDate = $date;
            $fuelByMonth[$date->format('Y-m')] = ($fuelByMonth[$date->format('Y-m')] ?? 0) + $amount;
            $odometerSeries[] = ['date' => $date, 'value' => $refuel->getOdometer()];
            if ((float) $refuel->getPricePerLiter() > 0) {
                $prices[] = (float) $refuel->getPricePerLiter();
                $priceSeries[] = ['date' => $date, 'value' => round((float) $refuel->getPricePerLiter(), 3)];
            }
            if ($row['segment'] !== null) {
                $segKm += $row['segment'][0];
                $segLiters += $row['segment'][1];
                $series[] = ['date' => $date, 'value' => round($row['efficiency'], 2)];
                $efficiencies[] = $row['efficiency'];
                if ($row['costPerKm'] !== null) {
                    $segCost += $row['segment'][2];
                    $costsPerKm[] = $row['costPerKm'];
                }
                $lastRow = $row;
            }
        }
        $start = $before ?? $minOdo;
        $km = ($maxOdo !== null && $start !== null) ? max(0, $maxOdo - $start) : 0;
        $efficiency = $segLiters > 0 ? $segKm / $segLiters : null;

        $otherCosts = $this->otherCosts($vehicle, $from, $to);
        $otherTotal = array_sum(array_column($otherCosts, 'total'));
        $totalCost = $fuelCost + $otherTotal;

        // Giorni del periodo: dall'inizio scelto (o dal primo dato) a oggi (o alla fine scelta).
        $today = new \DateTimeImmutable('today');
        $periodStart = $from ?? $firstDate;
        $periodEnd = $to && $to < $today ? $to : $today;
        $days = $periodStart ? max(1, (int) $periodStart->diff($periodEnd)->days + 1) : null;

        $lastEfficiency = null;
        foreach (array_reverse($rows) as $row) {
            if ($row['efficiency'] !== null) {
                $lastEfficiency = $row['efficiency'];
                break;
            }
        }
        $odometers = array_map(fn ($r) => $r['refuel']->getOdometer(), $rows);

        return [
            'refuels' => array_reverse($rows),
            'odometer' => $odometers ? max($odometers) : $vehicle->getInitialOdometer(),
            'efficiency' => $efficiency,
            'lastEfficiency' => $lastEfficiency,
            'perHundred' => $efficiency ? 100 / $efficiency : null,
            'fuelCost' => round($fuelCost, 2),
            'liters' => round($liters, 2),
            'avgPrice' => $liters > 0 ? $pumpCost / $liters : null,
            // Valore alla pompa (litri x prezzo): diverso dal pagato con buoni, spese divise o rifornimenti a 0 €.
            'pumpCost' => round($pumpCost, 2),
            'km' => $km,
            'fuelPerKm' => $km > 0 ? $fuelCost / $km : null,
            'otherCosts' => $otherCosts,
            'otherTotal' => round($otherTotal, 2),
            'totalCost' => round($totalCost, 2),
            'costPerKm' => $km > 0 ? $totalCost / $km : null,
            'series' => $series,
            'count' => $count,
            'unit' => $vehicle->isElectric() ? 'kWh' : 'l',
            // Sul modello di Drivvo
            'days' => $days,
            'totalPerDay' => $days ? $totalCost / $days : null,
            'fuelPerDay' => $days ? $fuelCost / $days : null,
            'otherPerDay' => $days ? $otherTotal / $days : null,
            'kmPerDay' => $days ? $km / $days : null,
            'litersPerRefuel' => $count ? $liters / $count : null,
            'litersPerDay' => $days ? $liters / $days : null,
            'priceMin' => $prices ? min($prices) : null,
            'priceMax' => $prices ? max($prices) : null,
            'efficiencyMin' => $efficiencies ? min($efficiencies) : null,
            'efficiencyMax' => $efficiencies ? max($efficiencies) : null,
            'lastPeriodEfficiency' => $lastRow['efficiency'] ?? null,
            'lastCostPerKm' => $lastRow['costPerKm'] ?? null,
            'segmentCostPerKm' => $segKm > 0 && $segCost > 0 ? $segCost / $segKm : null,
            'costPerKmMin' => $costsPerKm ? min($costsPerKm) : null,
            'costPerKmMax' => $costsPerKm ? max($costsPerKm) : null,
            'kmBetween' => $count > 1 && $maxOdo !== null ? ($maxOdo - $minOdo) / ($count - 1) : null,
            'daysBetween' => $count > 1 && $firstDate ? $firstDate->diff($lastDate)->days / ($count - 1) : null,
            'fullCount' => $fullCount,
            'autonomy' => $vehicle->getTankCapacity() && $efficiency ? $vehicle->getTankCapacity() * $efficiency : null,
            'monthlyFuel' => $days ? $fuelCost / $days * 30 : null,
            'monthly' => $this->monthly($vehicle, $fuelByMonth, $from ?? $firstDate, $periodEnd),
            'odometerSeries' => $odometerSeries,
            'priceSeries' => $priceSeries,
            'otherItems' => $this->otherItems($vehicle, $from, $to),
        ];
    }

    /** Ultimo dato utile per il form rifornimento: km e consumo dell'ultimo pieno. */
    public function lastOdometer(Vehicle $vehicle, ?int $excludeMovementId = null): ?int
    {
        return $this->refuels->findPrevious($vehicle, $excludeMovementId)?->getOdometer() ?? $vehicle->getInitialOdometer();
    }

    /**
     * Costi mese per mese nel periodo: carburante e altre spese del veicolo, mesi vuoti compresi.
     *
     * @param array<string, float> $fuelByMonth
     *
     * @return list<array{month: \DateTimeImmutable, fuel: float, other: float}>
     */
    private function monthly(Vehicle $vehicle, array $fuelByMonth, ?\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if (!$from) {
            return [];
        }
        $other = $this->db->fetchAllKeyValue(<<<'SQL'
            SELECT DATE_FORMAT(m.date, '%Y-%m') AS ym, SUM(m.amount)
            FROM movement m LEFT JOIN refuel r ON r.movement_id = m.id
            WHERE m.vehicle_id = :v AND m.kind = 'expense' AND r.id IS NULL AND m.date BETWEEN :from AND :to
            GROUP BY ym
            SQL, ['v' => $vehicle->getId(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]);
        $out = [];
        for ($month = $from->modify('first day of this month'); $month <= $to; $month = $month->modify('+1 month')) {
            $key = $month->format('Y-m');
            $out[] = ['month' => $month, 'fuel' => round($fuelByMonth[$key] ?? 0, 2), 'other' => round((float) ($other[$key] ?? 0), 2)];
        }

        return $out;
    }

    /**
     * Voci principali fra le spese non di carburante (per descrizione, o categoria se manca), dalla piu' alta.
     *
     * @return list<array{name: string, category: string, color: string, total: float, count: int}>
     */
    private function otherItems(Vehicle $vehicle, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $sql = <<<'SQL'
            SELECT COALESCE(NULLIF(TRIM(m.description), ''), c.name, :other) AS label, COALESCE(c.name, :other) AS category,
                   COALESCE(c.color, '#A3A59F') AS color, SUM(m.amount) AS total, COUNT(*) AS n
            FROM movement m
            LEFT JOIN category c ON c.id = m.category_id
            LEFT JOIN refuel r ON r.movement_id = m.id
            WHERE m.vehicle_id = :v AND m.kind = 'expense' AND r.id IS NULL
            SQL;
        $params = ['v' => $vehicle->getId(), 'other' => Trans::label('Altro')];
        if ($from) {
            $sql .= ' AND m.date >= :from';
            $params['from'] = $from->format('Y-m-d');
        }
        if ($to) {
            $sql .= ' AND m.date <= :to';
            $params['to'] = $to->format('Y-m-d');
        }
        $sql .= ' GROUP BY label, category, color ORDER BY total DESC LIMIT 12';

        return array_map(fn ($r) => ['name' => $r['label'], 'category' => $r['category'], 'color' => $r['color'],
            'total' => (float) $r['total'], 'count' => (int) $r['n']], $this->db->fetchAllAssociative($sql, $params));
    }

    /** @return list<array{name: string, color: string, total: float}> spese del veicolo che non sono rifornimenti */
    private function otherCosts(Vehicle $vehicle, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $sql = <<<'SQL'
            SELECT COALESCE(c.name, :other) AS name, COALESCE(c.color, '#A3A59F') AS color, SUM(m.amount) AS total
            FROM movement m
            LEFT JOIN category c ON c.id = m.category_id
            LEFT JOIN refuel r ON r.movement_id = m.id
            WHERE m.vehicle_id = :v AND m.kind = 'expense' AND r.id IS NULL
            SQL;
        $params = ['v' => $vehicle->getId(), 'other' => Trans::label('Altro')];
        if ($from) {
            $sql .= ' AND m.date >= :from';
            $params['from'] = $from->format('Y-m-d');
        }
        if ($to) {
            $sql .= ' AND m.date <= :to';
            $params['to'] = $to->format('Y-m-d');
        }
        $sql .= ' GROUP BY name, color ORDER BY total DESC';

        return array_map(fn ($r) => ['name' => $r['name'], 'color' => $r['color'], 'total' => (float) $r['total']],
            $this->db->fetchAllAssociative($sql, $params));
    }
}
