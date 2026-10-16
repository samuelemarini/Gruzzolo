<?php

namespace App\Controller;

use App\Entity\Movement;
use App\Entity\Vehicle;
use App\Repository\VehicleRepository;
use App\Service\ChartBuilder;
use App\Service\Palette;
use App\Service\VehicleStats;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/veicoli')]
final class VehicleController extends AppController
{
    private const PERIODS = ['3m' => '3 mesi', '12m' => '12 mesi', 'all' => 'Sempre'];

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
        $from = match ($period) {
            '3m' => new \DateTimeImmutable('today -3 months'),
            '12m' => new \DateTimeImmutable('today -12 months'),
            default => null,
        };
        $stats = $this->stats->compute($vehicle, $from);
        $series = array_slice($stats['series'], -12);
        $values = array_column($series, 'value');
        $avg = $stats['efficiency'];

        return $this->render('vehicle/show.html.twig', [
            'vehicle' => $vehicle,
            'stats' => $stats,
            'period' => $period,
            'periods' => self::PERIODS,
            'series' => $series,
            'chart' => $charts->lines(['eff' => $values, 'avg' => $avg ? array_fill(0, count($values), round($avg, 2)) : []], 320, 160),
        ]);
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
