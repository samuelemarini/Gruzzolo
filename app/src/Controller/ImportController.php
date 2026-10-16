<?php

namespace App\Controller;

use App\Entity\Category;
use App\Import\ImportService;
use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use App\Repository\ImportBatchRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/importa')]
final class ImportController extends AppController
{
    public function __construct(
        private readonly ImportService $imports,
        private readonly ImportBatchRepository $batches,
    ) {
    }

    #[Route('', name: 'import_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $user = $this->me();
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'import-upload');
            $file = $request->files->get('file');
            if (!$file || !$file->isValid()) {
                $error = 'Scegli un file CSV da caricare.';
            } else {
                try {
                    $key = $this->imports->store($user, $file);

                    return $this->redirectToRoute('import_review', ['key' => $key], 303);
                } catch (\InvalidArgumentException|\RuntimeException $e) {
                    $error = $e->getMessage();
                }
            }
        }

        return $this->render('import/index.html.twig', [
            'batches' => $this->batches->findForUser($user),
            'formats' => $this->imports->formatLabels(),
            'error' => $error,
        ], new Response(status: $error ? 422 : 200));
    }

    #[Route('/anteprima/{key}', name: 'import_review', requirements: ['key' => '[a-f0-9]{16}'], methods: ['GET', 'POST'])]
    public function review(Request $request, string $key, AccountRepository $accounts, CategoryRepository $categories): Response
    {
        $user = $this->me();
        if (!$this->imports->exists($user, $key)) {
            $this->addFlash('error', 'File non più disponibile: caricalo di nuovo');

            return $this->redirectToRoute('import_index');
        }

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'import-run');
            if ($request->request->has('_discard')) {
                $this->imports->discard($user, $key);

                return $this->redirectToRoute('import_index', [], 303);
            }
            $batch = $this->imports->import($user, $key, $this->pairs($request, 'wallet'), $this->pairs($request, 'cat'));
            $this->addFlash('success', sprintf('Importati %d movimenti', $batch->getImported()));

            return $this->redirectToRoute('import_index', ['done' => $batch->getId()], 303);
        }

        return $this->render('import/review.html.twig', [
            'a' => $this->imports->analyze($user, $key),
            'accounts' => $accounts->findForUser($user),
            // Ogni conto ha le sue categorie: nel menu sono raggruppate per conto.
            'categoryGroups' => array_map(fn ($a) => [
                'account' => $a,
                'expense' => $categories->findFlat($a, Category::EXPENSE),
                'income' => $categories->findFlat($a, Category::INCOME),
            ], $accounts->findForUser($user)),
        ]);
    }

    #[Route('/{id}/annulla', name: 'import_undo', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function undo(Request $request, int $id): Response
    {
        $batch = $this->own($this->batches->find($id));
        $this->checkCsrf($request, 'import-undo-'.$id);
        $deleted = $this->imports->undo($batch);
        $this->addFlash('success', sprintf('Importazione annullata: tolti %d movimenti', $deleted));

        return $this->redirectToRoute('import_index', [], 303);
    }

    /** I nomi di wallet e categorie possono contenere di tutto: arrivano come coppie indice → (chiave, scelta). */
    private function pairs(Request $request, string $name): array
    {
        $keys = $request->request->all($name.'_key');
        $values = $request->request->all($name.'_to');
        $out = [];
        foreach ($keys as $i => $k) {
            if (is_string($k) && isset($values[$i]) && is_string($values[$i])) {
                $out[$k] = $values[$i];
            }
        }

        return $out;
    }
}
