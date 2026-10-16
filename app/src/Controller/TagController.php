<?php

namespace App\Controller;

use App\Model\MovementFilter;
use App\Repository\MovementRepository;
use App\Repository\TagRepository;
use App\Service\Palette;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/etichette')]
final class TagController extends AppController
{
    public function __construct(
        private readonly TagRepository $tags,
        private readonly MovementRepository $movements,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'tag_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->me();
        $stats = $this->em->getConnection()->fetchAllAssociativeIndexed(<<<'SQL'
            SELECT t.id, COUNT(m.id) AS n, COALESCE(SUM(CASE WHEN m.kind = 'expense' THEN m.amount END), 0) AS expense,
                   MIN(m.date) AS first_date, MAX(m.date) AS last_date
            FROM tag t LEFT JOIN movement_tag mt ON mt.tag_id = t.id LEFT JOIN movement m ON m.id = mt.movement_id
            WHERE t.user_id = ? GROUP BY t.id
            SQL, [$user->getId()]);
        $tags = $this->tags->findForUser($user);
        usort($tags, fn ($a, $b) => ($stats[$b->getId()]['last_date'] ?? '') <=> ($stats[$a->getId()]['last_date'] ?? ''));

        return $this->render('tag/index.html.twig', ['tags' => $tags, 'stats' => $stats]);
    }

    #[Route('/{id}', name: 'tag_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $tag = $this->own($this->tags->find($id));
        $filter = new MovementFilter();
        $filter->tags = [$tag->getId()];
        $list = $this->movements->search($this->me(), $filter, 1000);
        usort($list, fn ($a, $b) => [$a->getDate(), $a->getId()] <=> [$b->getDate(), $b->getId()]);

        $expense = 0.0;
        $income = 0.0;
        $byCategory = [];
        foreach ($list as $m) {
            if ($m->isExpense()) {
                $expense += (float) $m->getAmount();
                $top = $m->getCategory()?->getParent() ?? $m->getCategory();
                $key = $top?->getId() ?? 0;
                $byCategory[$key] ??= ['name' => $top?->getName() ?? 'Senza categoria', 'color' => $top?->getColor() ?? '#A3A59F', 'total' => 0.0];
                $byCategory[$key]['total'] += (float) $m->getAmount();
            } elseif ($m->isIncome()) {
                $income += (float) $m->getAmount();
            }
        }
        usort($byCategory, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $this->render('tag/show.html.twig', [
            'tag' => $tag,
            'movements' => $list,
            'expense' => $expense,
            'income' => $income,
            'byCategory' => $byCategory,
            'first' => $list ? $list[0]->getDate() : null,
            'last' => $list ? end($list)->getDate() : null,
        ]);
    }

    #[Route('/{id}/modifica', name: 'tag_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $tag = $this->own($this->tags->find($id));
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'tag');
            $name = \App\Entity\Tag::normalize((string) $request->request->get('name'));
            $other = $name !== '' ? $this->tags->findOneByName($this->me(), $name) : null;
            if ($name === '') {
                $errors['name'] = 'Il nome non può essere vuoto';
            } elseif ($other && $other !== $tag) {
                $errors['name'] = 'Esiste già un\'etichetta #'.$name;
            }
            if (!$errors) {
                $color = (string) $request->request->get('color');
                $tag->setName($name)->setTitle((string) $request->request->get('title'))
                    ->setColor(array_key_exists($color, Palette::COLORS) ? $color : '#C3A6FF');
                $this->em->flush();
                $this->addFlash('success', 'Etichetta salvata');

                return $this->redirectToRoute('tag_show', ['id' => $tag->getId()], 303);
            }
        }

        return $this->render('tag/form.html.twig', ['tag' => $tag, 'errors' => $errors], new Response(status: $errors ? 422 : 200));
    }

    #[Route('/{id}/elimina', name: 'tag_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $tag = $this->own($this->tags->find($id));
        $this->checkCsrf($request, 'delete-tag-'.$id);
        $this->em->remove($tag);
        $this->em->flush();
        $this->addFlash('success', 'Etichetta eliminata (i movimenti restano)');

        return $this->redirectToRoute('tag_index', [], 303);
    }
}
