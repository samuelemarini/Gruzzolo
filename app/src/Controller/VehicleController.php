<?php

namespace App\Controller;

use App\Entity\Movement;
use App\Entity\Vehicle;
use App\Repository\VehicleRepository;
use App\Service\ChartBuilder;
use App\Service\Palette;
use App\Util\Money;
use App\Util\Param;
use App\Service\VehicleStats;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/veicoli')]
final class VehicleController extends AppController
{
    private const PERIODS = ['1m' => '1 mese', '12m' => '12 mesi', 'all' => 'Sempre', 'custom' => 'Periodo'];
    private const TABS = ['general' => 'Generale', 'fuel' => 'Rifornimenti', 'costs' => 'Spese'];
    /** Rifornimenti mostrati per pagina nella scheda Rifornimenti ("Mostra altri" ne aggiunge altrettanti). */
    private const REFUELS_PAGE = 30;

    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly VehicleStats $stats,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'vehicle_index', methods: ['GET'])]
    public function index(): Response
    {
        $list = $this->vehicles->findForUser($this->me(), true);
        $monthStart = new \DateTimeImmutable('first day of this month');
        $items = [];
        foreach ($list as $vehicle) {
            $items[] = [
                'vehicle' => $vehicle,
                'all' => $this->stats->compute($vehicle),
                'month' => $this->stats->compute($vehicle, $monthStart),
            ];
        }

        return $this->render('vehicle/index.html.twig', ['items' => $items]);
    }

    #[Route('/{id}', name: 'vehicle_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, int $id, ChartBuilder $charts): Response
    {
        $vehicle = $this->own($this->vehicles->find($id));
        $period = (string) $request->query->get('period', '12m');
        $period = array_key_exists($period, self::PERIODS) ? $period : '12m';
        $tab = (string) $request->query->get('tab', 'general');
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'general';
        $refuelsPage = max(1, Param::int($request->query, 'page', 1));
        $from = $to = null;
        switch ($period) {
            case '1m':
                $from = new \DateTimeImmutable('today -1 month');
                break;
            case '12m':
                $from = new \DateTimeImmutable('today -12 months');
                break;
            case 'custom':
                // Periodo personalizzato: di default dall'inizio dell'anno a oggi.
                $from = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('from')) ?: new \DateTimeImmutable('first day of january this year');
                $to = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('to')) ?: new \DateTimeImmutable('today');
                if ($to < $from) {
                    [$from, $to] = [$to, $from];
                }
                break;
        }
        $stats = $this->stats->compute($vehicle, $from, $to);
        $avg = $stats['efficiency'];
        // Con tanti pieni i grafici diventano illeggibili: oltre 36 punti si mostra la media di ogni mese (come Drivvo).
        $series = self::byMonth($stats['series']);
        $priceSeries = self::byMonth($stats['priceSeries']);
        $values = array_column($series, 'value');
        $prices = array_column($priceSeries, 'value');
        $odometers = array_column($stats['odometerSeries'], 'value');

        // Costi del veicolo: carburante e le categorie delle altre spese (per la ciambella).
        $palette = ['#8AB4FF', '#FFB86B', '#C3A6FF', '#F3E06A', '#FF9EC7', '#9BE7A0', '#D9B48F'];
        $costs = [['name' => 'Carburante', 'color' => $vehicle->getColor(), 'total' => $stats['fuelCost']]];
        foreach ($stats['otherCosts'] as $i => $c) {
            $costs[] = ['name' => $c['name'], 'color' => $palette[$i % count($palette)], 'total' => $c['total']];
        }
        $costs = array_values(array_filter($costs, fn ($c) => $c['total'] > 0));
        $others = array_slice($costs, $costs && $costs[0]['name'] === 'Carburante' ? 1 : 0);

        return $this->render('vehicle/show.html.twig', [
            'vehicle' => $vehicle,
            'stats' => $stats,
            'period' => $period,
            'periods' => self::PERIODS,
            'from' => $from,
            'to' => $to,
            // Parametri del periodo da tenere nei link (schede comprese).
            'range' => $period === 'custom' ? ['period' => 'custom', 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')] : ['period' => $period],
            'tab' => $tab,
            'tabs' => self::TABS,
            'refuelsPage' => $refuelsPage,
            'refuelsShown' => self::REFUELS_PAGE * $refuelsPage,
            'series' => $series,
            'priceSeries' => $priceSeries,
            'monthlyAveraged' => count($series) < count($stats['series']),
            'fuelBars' => $charts->bars($stats['monthly'], ['fuel']),
            'chart' => $charts->lines(['eff' => $values, 'avg' => $avg ? array_fill(0, count($values), round($avg, 2)) : []], 320, 160),
            'priceChart' => $charts->lines(['price' => $prices, 'avg' => $stats['avgPrice'] ? array_fill(0, count($prices), round($stats['avgPrice'], 3)) : []], 320, 150),
            'odometerChart' => $charts->lines(['odo' => $odometers], 320, 150),
            'monthlyBars' => $charts->bars(array_map(fn ($m) => $m + ['total' => $m['fuel'] + $m['other']], $stats['monthly']), ['total']),
            'costs' => $costs,
            'costsDonut' => $charts->donut(array_map(fn ($c) => ['value' => $c['total'], 'color' => $c['color'], 'label' => $c['name']], $costs), 180, 22),
            'others' => $others,
            'othersDonut' => $charts->donut(array_map(fn ($c) => ['value' => $c['total'], 'color' => $c['color'], 'label' => $c['name']], $others), 180, 22),
        ]);
    }

    /**
     * @param list<array{date: \DateTimeImmutable, value: float}> $series
     *
     * @return list<array{date: \DateTimeImmutable, value: float}> la serie, o la media di ogni mese se ha piu' di 36 punti
     */
    private static function byMonth(array $series): array
    {
        if (count($series) <= 36) {
            return $series;
        }
        $months = [];
        foreach ($series as $point) {
            $months[$point['date']->format('Y-m')][] = $point;
        }

        return array_values(array_map(fn ($points) => [
            'date' => $points[0]['date']->modify('first day of this month'),
            'value' => round(array_sum(array_column($points, 'value')) / count($points), 3),
        ], $months));
    }

    #[Route('/nuovo', name: 'vehicle_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->form($request, new Vehicle($this->me()));
    }

    #[Route('/{id}/modifica', name: 'vehicle_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        return $this->form($request, $this->own($this->vehicles->find($id)));
    }

    #[Route('/{id}/elimina', name: 'vehicle_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $vehicle = $this->own($this->vehicles->find($id));
        $this->checkCsrf($request, 'delete-vehicle-'.$id);
        if ($this->em->getRepository(Movement::class)->count(['vehicle' => $vehicle]) > 0) {
            $this->addFlash('error', 'Il veicolo ha dei movimenti: archivialo per conservare lo storico');

            return $this->redirectToRoute('vehicle_edit', ['id' => $id], 303);
        }
        $this->em->remove($vehicle);
        $this->em->flush();
        $this->addFlash('success', 'Veicolo eliminato');

        return $this->redirectToRoute('vehicle_index', [], 303);
    }

    private function form(Request $request, Vehicle $vehicle): Response
    {
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'vehicle');
            $data = $request->request;
            $vehicle->setName((string) $data->get('name'))->setKind((string) $data->get('kind'))
                ->setFuelType((string) $data->get('fuel_type'))->setPlate((string) $data->get('plate'))
                ->setArchived($data->getBoolean('archived'));
            $odometer = preg_replace('/\D/', '', (string) $data->get('initial_odometer'));
            $vehicle->setInitialOdometer($odometer !== '' ? (int) $odometer : null);
            $tank = Money::parse((string) $data->get('tank_capacity'));
            $vehicle->setTankCapacity($tank !== null ? (float) $tank : null);
            $color = (string) $data->get('color');
            $vehicle->setColor(array_key_exists($color, Palette::COLORS) ? $color : '#6FDCCB');
            if ($vehicle->getName() === '') {
                $errors['name'] = 'Dai un nome al veicolo';
            }
            if (!$errors) {
                $this->em->persist($vehicle);
                $this->em->flush();
                $this->addFlash('success', 'Veicolo salvato');

                return $this->back($request, 'vehicle_show', ['id' => $vehicle->getId()]);
            }
        }

        return $this->render('vehicle/form.html.twig', [
            'vehicle' => $vehicle, 'errors' => $errors, 'kinds' => Vehicle::KINDS, 'fuels' => Vehicle::FUELS,
            'back' => (string) ($request->request->get('_back') ?: $request->query->get('back', '')),
        ], new Response(status: $errors ? 422 : 200));
    }
}
