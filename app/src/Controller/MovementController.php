<?php

namespace App\Controller;

use App\Util\Param;
use App\Entity\Category;
use App\Entity\Movement;
use App\Entity\Refuel;
use App\Entity\Tag;
use App\Model\MovementFilter;
use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use App\Repository\MovementRepository;
use App\Repository\TagRepository;
use App\Repository\VehicleRepository;
use App\Service\VehicleStats;
use App\Util\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/movimenti')]
final class MovementController extends AppController
{
    private const PAGE = 60;

    public function __construct(
        private readonly MovementRepository $movements,
        private readonly CategoryRepository $categories,
        private readonly AccountRepository $accounts,
        private readonly TagRepository $tags,
        private readonly VehicleRepository $vehicles,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'movement_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->me();
        $filter = MovementFilter::fromRequest($request, $this->accounts->findDefault($user)?->getId());
        $page = max(1, Param::int($request->query, 'page', 1));
        $list = $this->movements->search($user, $filter, self::PAGE * $page);

        // Conti che si stanno guardando: i giroconti che ne escono o vi entrano da fuori contano come
        // uscite/entrate (anche nel totale del giorno), quelli fra due di questi conti no.
        $scope = $filter->accounts ?: array_map(fn ($a) => $a->getId(), $this->accounts->findForUser($user, true));
        $groups = [];
        foreach ($list as $m) {
            $key = $m->getDate()->format('Y-m-d');
            $groups[$key] ??= ['date' => $m->getDate(), 'movements' => [], 'total' => 0.0];
            $groups[$key]['movements'][] = $m;
            if (!$m->isTransfer()) {
                $groups[$key]['total'] += $m->getSignedAmount();
            } else {
                $out = in_array($m->getAccount()->getId(), $scope, true);
                $in = $m->getToAccount() !== null && in_array($m->getToAccount()->getId(), $scope, true);
                $groups[$key]['total'] += $out === $in ? 0 : ($in ? 1 : -1) * (float) $m->getAmount();
            }
        }
        $totals = $this->movements->totals($user, $filter);

        return $this->render('movement/index.html.twig', [
            'filter' => $filter,
            'groups' => $groups,
            'totals' => $totals,
            'scope' => $scope,
            'hasMore' => $totals['count'] > count($list),
            'page' => $page,
            'categoryTrees' => array_map(fn ($a) => ['account' => $a, 'tree' => $this->categories->findTree($a)], $this->accounts->findForUser($user)),
            'tags' => $this->tags->findForUser($user),
            'accounts' => $this->accounts->findForUser($user, true),
            'vehicles' => $this->vehicles->findForUser($user, true),
            'month' => $filter->period === 'month' ? $filter->from : null,
        ]);
    }

    /** Esporta in CSV (separatore ";" e virgola decimale: si apre direttamente in Excel italiano). */
    #[Route('/esporta.csv', name: 'movement_export', methods: ['GET'])]
    public function export(Request $request): StreamedResponse
    {
        $user = $this->me();
        $filter = MovementFilter::fromRequest($request, $this->accounts->findDefault($user)?->getId());
        $list = $this->movements->search($user, $filter, 100000);

        $response = new StreamedResponse(function () use ($list) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Data', 'Tipo', 'Importo', 'Conto', 'Verso conto', 'Categoria', 'Descrizione', 'Etichette', 'Veicolo', 'Km', 'Litri', 'Prezzo/l', 'Nota'], ';', '"', '');
            foreach ($list as $m) {
                $r = $m->getRefuel();
                fputcsv($out, [
                    $m->getDate()->format('d/m/Y'),
                    Movement::KINDS[$m->getKind()],
                    number_format($m->isTransfer() ? (float) $m->getAmount() : $m->getSignedAmount(), 2, ',', ''),
                    $m->getAccount()->getName(),
                    $m->getToAccount()?->getName() ?? '',
                    $m->getCategory()?->getFullName() ?? '',
                    $m->getDescription(),
                    implode(' ', array_map(fn (Tag $t) => '#'.$t->getName(), $m->getTags()->toArray())),
                    $m->getVehicle()?->getName() ?? '',
                    $r ? $r->getOdometer() : '',
                    $r ? number_format((float) $r->getLiters(), 2, ',', '') : '',
                    $r ? number_format((float) $r->getPricePerLiter(), 3, ',', '') : '',
                    $m->getNote() ?? '',
                ], ';', '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="movimenti-'.date('Y-m-d').'.csv"');

        return $response;
    }

    #[Route('/nuovo', name: 'movement_new', methods: ['GET', 'POST'])]
    public function new(Request $request, VehicleStats $vehicleStats): Response
    {
        $user = $this->me();
        // Chi ha solo conti condivisi con lui parte dal primo di quelli.
        $account = $this->accounts->findDefault($user) ?? ($this->accounts->findForUser($user)[0] ?? null);
        if (!$account) {
            $this->addFlash('error', 'Crea prima un conto.');

            return $this->redirectToRoute('account_new');
        }
        $movement = new Movement($user, $account);
        $kind = (string) $request->query->get('kind', Movement::EXPENSE);
        $movement->setKind($kind);
        if ($accountId = Param::int($request->query, 'account')) {
            $chosen = $this->accounts->find($accountId);
            if ($chosen && $chosen->isAccessibleBy($user)) {
                $movement->setAccount($chosen);
            }
        }
        if ($request->query->getBoolean('fuel')) {
            $movement->setCategory($this->categories->findOneBy(['account' => $movement->getAccount(), 'fuel' => true, 'archived' => false]));
        }

        return $this->handleForm($request, $movement, $vehicleStats);
    }

    #[Route('/{id}', name: 'movement_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id, VehicleStats $vehicleStats): Response
    {
        $movement = $this->accessibleMovement($this->movements->find($id));
        if ($entry = $movement->getInvestmentEntry()) {
            // I versamenti si modificano dall'investimento, che tiene allineato il giroconto.
            return $this->redirectToRoute('investment_entry_edit', ['id' => $entry->getId()]);
        }

        return $this->handleForm($request, $movement, $vehicleStats);
    }

    #[Route('/{id}/duplica', name: 'movement_duplicate', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function duplicate(Request $request, int $id, VehicleStats $vehicleStats): Response
    {
        $source = $this->accessibleMovement($this->movements->find($id));
        $copy = (new Movement($this->me(), $source->getAccount()))
            ->setKind($source->getKind())->setAmount($source->getAmount())
            ->setDescription($source->getDescription())->setNote($source->getNote())
            ->setCategory($source->getCategory())->setToAccount($source->getToAccount())->setVehicle($source->getVehicle());
        foreach ($source->getTags() as $tag) {
            $copy->addTag($tag);
        }

        return $this->handleForm($request, $copy, $vehicleStats);
    }

    #[Route('/{id}/elimina', name: 'movement_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $movement = $this->accessibleMovement($this->movements->find($id));
        $this->checkCsrf($request, 'delete-movement-'.$id);
        $this->em->remove($movement);
        $this->em->flush();
        $this->addFlash('success', 'Movimento eliminato');

        return $this->back($request, 'movement_index');
    }

    private function handleForm(Request $request, Movement $movement, VehicleStats $vehicleStats): Response
    {
        $user = $this->me();
        $errors = [];
        $data = $request->request;

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'movement');
            $errors = $this->apply($movement, $request);
            if (!$errors) {
                $isNew = $movement->getId() === null;
                $this->em->persist($movement);
                $this->em->flush();
                $this->addFlash('success', $isNew ? 'Movimento salvato' : 'Modifiche salvate');

                if ($isNew && $data->getBoolean('_another')) {
                    return $this->redirectToRoute('movement_new', ['kind' => $movement->getKind()], 303);
                }

                return $this->back($request, 'movement_index');
            }
        }

        $vehicles = $this->vehicles->findForUser($user);
        // Anche il veicolo gia' scelto se archiviato o di chi ha inserito il movimento (conto condiviso).
        if ($movement->getVehicle() && !in_array($movement->getVehicle(), $vehicles, true)) {
            $vehicles[] = $movement->getVehicle();
        }
        $lastOdometers = [];
        foreach ($vehicles as $vehicle) {
            $lastOdometers[$vehicle->getId()] = $vehicleStats->lastOdometer($vehicle, $movement->getId());
        }

        return $this->render('movement/form.html.twig', [
            'movement' => $movement,
            'errors' => $errors,
            // Le categorie di ogni conto: il form mostra quelle del conto scelto.
            'categoryTrees' => array_map(fn ($a) => [
                'account' => $a,
                'expense' => $this->categories->findTree($a, Category::EXPENSE),
                'income' => $this->categories->findTree($a, Category::INCOME),
            ], $accounts = $this->accounts->findForUser($user)),
            'accounts' => $accounts,
            'vehicles' => $vehicles,
            'lastOdometers' => $lastOdometers,
            'tagOptions' => $this->tags->findForUser($user),
            'descriptions' => $this->movements->recentDescriptions($user),
            'back' => (string) ($data->get('_back') ?: $request->query->get('back', '')),
        ], new Response(status: $errors ? 422 : 200));
    }

    /** Copia i campi del form nel movimento. @return array<string, string> errori per campo */
    private function apply(Movement $m, Request $request): array
    {
        $user = $this->me();
        $data = $request->request;
        $errors = [];

        $m->setKind((string) $data->get('kind'));
        $m->setDescription((string) $data->get('description'));
        $m->setNote((string) $data->get('note'));

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $data->get('date'));
        $date ? $m->setDate($date) : $errors['date'] = 'Data non valida';

        $account = $this->accounts->find(Param::int($data, 'account'));
        if ($account && $account->isAccessibleBy($user)) {
            $m->setAccount($account);
        } else {
            $errors['account'] = 'Scegli un conto';
        }

        $amount = Money::parse($data->get('amount'));

        // Rifornimento: il terzo valore fra totale, litri e prezzo si ricava dagli altri due.
        $category = null;
        if (!$m->isTransfer()) {
            $category = $this->categories->find(Param::int($data, 'category'));
            // Solo categorie del conto del movimento (ogni conto ha le sue).
            $usable = $category && ($category->getAccount() === $m->getAccount() || $category === $m->getCategory());
            if (!$usable || $category->getKind() !== $m->getKind()) {
                $category = null;
                $errors['category'] = 'Scegli una categoria';
            }
        }
        $m->setCategory($category);
        $m->setToAccount(null);

        $vehicle = null;
        if ($vehicleId = Param::int($data, 'vehicle')) {
            $vehicle = $this->vehicles->find($vehicleId);
            if (!$vehicle || ($vehicle->getUser() !== $user && $vehicle !== $m->getVehicle())) {
                $vehicle = null;
            }
        }

        if ($m->isTransfer()) {
            $to = $this->accounts->find(Param::int($data, 'to_account'));
            if (!$to || !$to->isAccessibleBy($user)) {
                $errors['to_account'] = 'Scegli il conto di destinazione';
            } elseif ($account === $to) {
                $errors['to_account'] = 'I due conti devono essere diversi';
            } else {
                $m->setToAccount($to);
            }
            $vehicle = null;
        }

        $refuelData = $data->all('refuel');
        if ($category?->isFuel() && $m->isExpense()) {
            $liters = Money::parse($refuelData['liters'] ?? '', 2);
            $price = Money::parse($refuelData['price'] ?? '', 3);
            if ($amount === null && $liters && $price) {
                $amount = number_format((float) $liters * (float) $price, 2, '.', '');
            } elseif ($liters === null && $amount && $price && (float) $price > 0) {
                $liters = number_format((float) $amount / (float) $price, 2, '.', '');
            } elseif ($price === null && $amount && $liters && (float) $liters > 0) {
                $price = number_format((float) $amount / (float) $liters, 3, '.', '');
            }
            $odometer = (int) preg_replace('/\D/', '', (string) ($refuelData['odometer'] ?? ''));
            if (!$vehicle) {
                $errors['vehicle'] = 'Scegli il veicolo';
            }
            if ($odometer <= 0) {
                $errors['odometer'] = 'Indica i chilometri';
            }
            if (!$liters || (float) $liters <= 0 || !$price || (float) $price <= 0) {
                $errors['liters'] = 'Servono due valori fra totale, litri e prezzo';
            }
            $refuel = $m->getRefuel() ?? new Refuel($m);
            $refuel->setOdometer(max(0, $odometer))
                ->setLiters($liters ?? '0.00')->setPricePerLiter($price ?? '0.000')
                ->setFullTank(!empty($refuelData['full']))->setMissedPrevious(!empty($refuelData['missed']));
        } elseif ($m->getRefuel()) {
            $this->em->remove($m->getRefuel());
            $m->setRefuel(null);
        }
        $m->setVehicle($m->isExpense() ? $vehicle : null);

        if ($amount === null || (float) $amount <= 0) {
            $errors['amount'] = 'Inserisci un importo';
        } else {
            $m->setAmount($amount);
        }

        $current = [];
        foreach ($m->getTags() as $tag) {
            $current[$tag->getName()] = $tag;
        }
        $m->clearTags();
        foreach (array_unique(array_filter(array_map([Tag::class, 'normalize'], $data->all('tags')))) as $name) {
            $tag = $current[$name] ?? $this->tags->findOneByName($user, $name) ?? $this->newTag($name);
            $m->addTag($tag);
        }

        return $errors;
    }

    /** @var array<string, Tag> etichette create in questa richiesta, non ancora a DB */
    private array $newTags = [];

    private function newTag(string $name): Tag
    {
        if (!isset($this->newTags[$name])) {
            $this->newTags[$name] = new Tag($this->me(), $name);
            $this->em->persist($this->newTags[$name]);
        }

        return $this->newTags[$name];
    }
}
