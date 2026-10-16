<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Movement;
use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use App\Service\Palette;
use App\Util\Param;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Categorie per conto: ogni conto ha le sue. Chi condivide un conto ne gestisce le categorie
 * insieme al proprietario (sono le stesse per entrambi).
 */
#[Route('/categorie')]
final class CategoryController extends AppController
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly AccountRepository $accounts,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'category_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $account = $this->currentAccount($request);
        $kind = $request->query->get('kind') === Category::INCOME ? Category::INCOME : Category::EXPENSE;

        return $this->render('category/index.html.twig', [
            'account' => $account,
            'accounts' => $this->accounts->findForUser($this->me()),
            'kind' => $kind,
            'tree' => $this->categories->findTree($account, $kind, true),
            'counts' => $this->usage($account),
            'expenseCount' => count($this->categories->findBy(['account' => $account, 'kind' => Category::EXPENSE, 'parent' => null])),
            'incomeCount' => count($this->categories->findBy(['account' => $account, 'kind' => Category::INCOME, 'parent' => null])),
        ]);
    }

    /** Ordine delle categorie: e' quello con cui vengono proposte quando si inserisce un movimento. */
    #[Route('/ordina', name: 'category_sort', methods: ['GET'])]
    public function sort(Request $request): Response
    {
        $account = $this->currentAccount($request);
        $kind = $request->query->get('kind') === Category::INCOME ? Category::INCOME : Category::EXPENSE;

        return $this->render('category/sort.html.twig', [
            'account' => $account,
            'kind' => $kind,
            'tree' => $this->categories->findTree($account, $kind),
            'counts' => $this->usage($account),
        ]);
    }

    /** Salva l'ordine di un gruppo di categorie sorelle (le principali di un tipo, o le figlie di una). */
    #[Route('/ordina', name: 'category_sort_save', methods: ['POST'])]
    public function sortSave(Request $request): Response
    {
        $this->checkCsrf($request, 'category-sort');
        $ids = array_values(array_filter(array_map('intval', $request->request->all('ids'))));
        $group = null;
        foreach ($ids as $position => $id) {
            $category = $this->accessibleCategory($this->categories->find($id));
            // Tutte sorelle: stesso conto, stesso padre e stesso tipo, altrimenti l'ordine non ha senso.
            $key = $category->getAccount()->getId().'|'.($category->getParent()?->getId() ?? 0).'|'.$category->getKind();
            if ($group !== null && $group !== $key) {
                return new Response('Categorie di gruppi diversi', 400);
            }
            $group = $key;
            $category->setPosition($position);
        }
        $this->em->flush();

        return new Response(null, 204);
    }

    /** Le piu' usate per prime (conteggio di tutti i movimenti, sottocategorie comprese nel padre). */
    #[Route('/ordina/per-utilizzo', name: 'category_sort_usage', methods: ['POST'])]
    public function sortByUsage(Request $request): Response
    {
        $this->checkCsrf($request, 'category-sort');
        $account = $this->accessibleAccount($this->accounts->find(Param::int($request->request, 'account')));
        $kind = $request->request->get('kind') === Category::INCOME ? Category::INCOME : Category::EXPENSE;
        $counts = $this->usage($account);

        $total = fn (Category $c) => ($counts[$c->getId()] ?? 0) + array_sum(array_map(fn ($ch) => $counts[$ch->getId()] ?? 0, $c->getChildren()->toArray()));
        foreach (self::byCount($this->categories->findTree($account, $kind, true), $total) as $position => $parent) {
            $parent->setPosition($position);
            foreach (self::byCount($parent->getChildren()->toArray(), fn ($c) => $counts[$c->getId()] ?? 0) as $childPosition => $child) {
                $child->setPosition($childPosition);
            }
        }
        $this->em->flush();
        $this->addFlash('success', 'Categorie ordinate dalla più usata');

        return $this->redirectToRoute('category_sort', ['account' => $account->getId(), 'kind' => $kind], 303);
    }

    /** Copia nel conto le categorie di un altro (quelle che ha gia' non si duplicano). */
    #[Route('/copia', name: 'category_copy', methods: ['POST'])]
    public function copy(Request $request): Response
    {
        $this->checkCsrf($request, 'category-copy');
        $to = $this->accessibleAccount($this->accounts->find(Param::int($request->request, 'account')));
        $from = $this->accessibleAccount($this->accounts->find(Param::int($request->request, 'from')));
        $created = $from === $to ? 0 : $this->categories->copyAll($from, $to);
        $this->em->flush();
        $this->addFlash('success', $created ? sprintf('Copiate %d categorie da «%s»', $created, $from->getName()) : 'Nessuna categoria nuova da copiare');

        return $this->redirectToRoute('category_index', ['account' => $to->getId()], 303);
    }

    #[Route('/nuova', name: 'category_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $account = $this->currentAccount($request);
        $category = new Category($account);
        $category->setKind((string) $request->query->get('kind', Category::EXPENSE));
        if ($parentId = Param::int($request->query, 'parent')) {
            $parent = $this->accessibleCategory($this->categories->find($parentId));
            $category = (new Category($parent->getAccount()))->setParent($parent)->setKind($parent->getKind())->setColor($parent->getColor());
        }

        return $this->form($request, $category);
    }

    #[Route('/{id}', name: 'category_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        return $this->form($request, $this->accessibleCategory($this->categories->find($id)));
    }

    #[Route('/{id}/elimina', name: 'category_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $category = $this->accessibleCategory($this->categories->find($id));
        $this->checkCsrf($request, 'delete-category-'.$id);
        $used = $this->em->getRepository(Movement::class)->count(['category' => $category])
            + array_sum(array_map(fn ($c) => $this->em->getRepository(Movement::class)->count(['category' => $c]), $category->getChildren()->toArray()));
        if ($used > 0) {
            // Con dei movimenti si archivia: sparisce dai selettori ma lo storico resta.
            $category->setArchived(true);
            foreach ($category->getChildren() as $child) {
                $child->setArchived(true);
            }
            $this->addFlash('success', 'Categoria archiviata: i movimenti passati la mantengono');
        } else {
            foreach ($category->getChildren() as $child) {
                $this->em->remove($child);
            }
            $this->em->remove($category);
            $this->addFlash('success', 'Categoria eliminata');
        }
        $this->em->flush();

        return $this->redirectToRoute('category_index', ['account' => $category->getAccount()->getId(), 'kind' => $category->getKind()], 303);
    }

    private function form(Request $request, Category $category): Response
    {
        $account = $category->getAccount();
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'category');
            $data = $request->request;
            $category->setName((string) $data->get('name'));
            if ($category->getName() === '') {
                $errors['name'] = 'Dai un nome alla categoria';
            }
            if ($category->getId() === null && $category->getParent() === null) {
                $category->setKind((string) $data->get('kind'));
            }
            $parent = null;
            if ($parentId = Param::int($data, 'parent')) {
                $parent = $this->categories->find($parentId);
                if (!$parent || $parent->getAccount() !== $account || $parent === $category || $parent->getParent() !== null
                    || $parent->getKind() !== $category->getKind() || $category->getChildren()->count() > 0) {
                    $parent = null;
                    $errors['parent'] = 'Categoria principale non valida (sono ammessi due livelli)';
                }
            }
            if ($parent !== $category->getParent() || $category->getId() === null) {
                $category->setPosition($this->categories->nextPosition($account, $parent));
            }
            $category->setParent($parent);
            $icon = (string) $data->get('icon');
            $category->setIcon(in_array($icon, Palette::CATEGORY_ICONS, true) ? $icon : 'circle-ellipsis');
            $color = (string) $data->get('color');
            $category->setColor(array_key_exists($color, Palette::COLORS) ? $color : '#A3A59F');
            $category->setFuel($category->getKind() === Category::EXPENSE && $data->getBoolean('fuel'));
            $category->setArchived($data->getBoolean('archived'));

            if (!$errors) {
                $this->em->persist($category);
                $this->em->flush();
                $this->addFlash('success', 'Categoria salvata');

                return $this->redirectToRoute('category_index', ['account' => $account->getId(), 'kind' => $category->getKind()], 303);
            }
        }

        return $this->render('category/form.html.twig', [
            'category' => $category,
            'errors' => $errors,
            'parents' => array_filter($this->categories->findTree($account, $category->getKind()), fn ($p) => $p !== $category),
            'icons' => Palette::CATEGORY_ICONS,
        ], new Response(status: $errors ? 422 : 200));
    }

    /** Conto scelto (?account=), altrimenti il predefinito dell'utente. */
    private function currentAccount(Request $request): Account
    {
        if ($id = Param::int($request->query, 'account')) {
            return $this->accessibleAccount($this->accounts->find($id));
        }

        return $this->accounts->findDefault($this->me()) ?? $this->accessibleAccount($this->accounts->findForUser($this->me())[0] ?? null);
    }

    private function accessibleCategory(?Category $category): Category
    {
        if ($category === null || !$category->isAccessibleBy($this->me())) {
            throw $this->createNotFoundException();
        }

        return $category;
    }

    /**
     * Dalla piu' usata alla meno usata, a parita' in ordine alfabetico.
     *
     * @param list<Category> $categories
     *
     * @return list<Category>
     */
    private static function byCount(array $categories, callable $count): array
    {
        usort($categories, fn (Category $a, Category $b) => [$count($b), $a->getName()] <=> [$count($a), $b->getName()]);

        return $categories;
    }

    /** @return array<int, int> numero di movimenti per categoria del conto (di chiunque li abbia inseriti) */
    private function usage(Account $account): array
    {
        $rows = $this->em->getConnection()->fetchAllKeyValue(
            'SELECT m.category_id, COUNT(*) FROM movement m JOIN category c ON c.id = m.category_id WHERE c.account_id = ? GROUP BY m.category_id', [$account->getId()]);

        return array_map('intval', $rows);
    }
}
