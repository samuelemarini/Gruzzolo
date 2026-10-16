<?php

namespace App\Service;

use App\Entity\Refuel;
use App\Entity\Vehicle;
use App\Repository\RefuelRepository;
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
     * @return array{
     *   refuels: list<array{refuel: Refuel, efficiency: ?float, distance: ?int}>,
     *   odometer: ?int, efficiency: ?float, lastEfficiency: ?float, perHundred: ?float,
     *   fuelCost: float, liters: float, avgPrice: ?float, km: int, fuelPerKm: ?float,
     *   otherCosts: list<array{name: string, color: string, total: float}>, totalCost: float, costPerKm: ?float,
     *   series: list<array{date: \DateTimeImmutable, value: float}>, count: int, unit: string
     * }
     */
    public function compute(Vehicle $vehicle, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $all = $this->refuels->findForVehicle($vehicle);
        $rows = [];
        $lastFull = null;
        $litersSince = 0.0;
        $prevOdometer = $vehicle->getInitialOdometer();
        foreach ($all as $refuel) {
            $efficiency = null;
            $segment = null;
            $distance = $prevOdometer !== null ? $refuel->getOdometer() - $prevOdometer : null;
            if ($refuel->isMissedPrevious()) {
                $lastFull = $refuel->isFullTank() ? $refuel : null;
                $litersSince = 0.0;
            } else {
                $litersSince += (float) $refuel->getLiters();
                if ($refuel->isFullTank()) {
                    if ($lastFull !== null && $litersSince > 0) {
                        $km = $refuel->getOdometer() - $lastFull->getOdometer();
                        if ($km > 0) {
                            $efficiency = $km / $litersSince;
                            $segment = [$km, $litersSince];
                        }
                    }
                    $lastFull = $refuel;
                    $litersSince = 0.0;
                }
            }
            $rows[] = ['refuel' => $refuel, 'efficiency' => $efficiency, 'distance' => $distance, 'segment' => $segment];
            $prevOdometer = $refuel->getOdometer();
        }

        $inPeriod = static fn (\DateTimeImmutable $d) => (!$from || $d >= $from) && (!$to || $d <= $to);

        // Media del periodo: km/l pesato sui segmenti che finiscono nel periodo.
        $segKm = 0.0;
        $segLiters = 0.0;
        $series = [];
        $fuelCost = 0.0;
        $liters = 0.0;
        $pumpCost = 0.0;
        $count = 0;
        $minOdo = null;
        $maxOdo = null;
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
            $fuelCost += (float) $refuel->getMovement()->getAmount();
            $liters += (float) $refuel->getLiters();
            // Prezzo alla pompa: da litri e €/l, non da quanto hai pagato (buoni, spese divise, rifornimenti a 0 €).
            $pumpCost += (float) $refuel->getLiters() * (float) $refuel->getPricePerLiter();
            $minOdo = $minOdo === null ? $refuel->getOdometer() : min($minOdo, $refuel->getOdometer());
            $maxOdo = max($maxOdo ?? 0, $refuel->getOdometer());
            if ($row['segment'] !== null) {
                $segKm += $row['segment'][0];
                $segLiters += $row['segment'][1];
                $series[] = ['date' => $date, 'value' => round($row['efficiency'], 2)];
            }
        }
        $start = $before ?? $minOdo;
        $km = ($maxOdo !== null && $start !== null) ? max(0, $maxOdo - $start) : 0;
        $efficiency = $segLiters > 0 ? $segKm / $segLiters : null;

        $otherCosts = $this->otherCosts($vehicle, $from, $to);
        $totalCost = $fuelCost + array_sum(array_column($otherCosts, 'total'));

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
            'km' => $km,
            'fuelPerKm' => $km > 0 ? $fuelCost / $km : null,
            'otherCosts' => $otherCosts,
            'totalCost' => round($totalCost, 2),
            'costPerKm' => $km > 0 ? $totalCost / $km : null,
            'series' => $series,
            'count' => $count,
            'unit' => $vehicle->isElectric() ? 'kWh' : 'l',
        ];
    }

    /** Ultimo dato utile per il form rifornimento: km e consumo dell'ultimo pieno. */
    public function lastOdometer(Vehicle $vehicle, ?int $excludeMovementId = null): ?int
    {
        return $this->refuels->findPrevious($vehicle, $excludeMovementId)?->getOdometer() ?? $vehicle->getInitialOdometer();
    }

    /** @return list<array{name: string, color: string, total: float}> spese del veicolo che non sono rifornimenti */
    private function otherCosts(Vehicle $vehicle, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $sql = <<<'SQL'
            SELECT COALESCE(c.name, 'Altro') AS name, COALESCE(c.color, '#A3A59F') AS color, SUM(m.amount) AS total
            FROM movement m
            LEFT JOIN category c ON c.id = m.category_id
            LEFT JOIN refuel r ON r.movement_id = m.id
            WHERE m.vehicle_id = :v AND m.kind = 'expense' AND r.id IS NULL
            SQL;
        $params = ['v' => $vehicle->getId()];
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
