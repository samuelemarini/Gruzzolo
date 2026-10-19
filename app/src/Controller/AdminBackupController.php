<?php

namespace App\Controller;

use App\Service\BackupService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Backup dell'intera istanza (tutti gli utenti e i loro dati) e ripristino che sostituisce tutto. */
#[Route('/admin/backup')]
#[IsGranted('ROLE_ADMIN')]
final class AdminBackupController extends AppController
{
    public const CONFIRM = 'RIPRISTINA';

    public function __construct(private readonly BackupService $backups)
    {
    }

    #[Route('', name: 'admin_backup', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'backup-upload');
            $file = $request->files->get('file');
            if (!$file || !$file->isValid()) {
                $error = 'Scegli il file di backup da caricare.';
            } else {
                try {
                    return $this->redirectToRoute('admin_backup_review', ['key' => $this->backups->store($file->getPathname())], 303);
                } catch (\InvalidArgumentException|\RuntimeException $e) {
                    $error = $e->getMessage();
                }
            }
        }

        $counts = $this->backups->counts();

        return $this->render('admin/backup.html.twig', [
            'users' => $counts['app_user'] ?? 0,
            'movements' => $counts['movement'] ?? 0,
            'error' => $error,
        ], new Response(status: $error ? 422 : 200));
    }

    #[Route('/scarica', name: 'admin_backup_download', methods: ['GET'])]
    public function download(): Response
    {
        $response = new StreamedResponse(function (): void {
            $out = fopen('php://output', 'w');
            $this->backups->write($out);
            fclose($out);
        });
        $response->headers->set('Content-Type', 'application/json; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->backups->filename()));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/ripristina/{key}', name: 'admin_backup_review', requirements: ['key' => '[a-f0-9]{16}'], methods: ['GET', 'POST'])]
    public function review(Request $request, string $key, Security $security): Response
    {
        if (!$this->backups->exists($key)) {
            $this->addFlash('error', 'File non più disponibile: caricalo di nuovo');

            return $this->redirectToRoute('admin_backup');
        }
        $data = $this->backups->loadStored($key);
        $error = null;

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'backup-restore-'.$key);
            if ($request->request->has('_discard')) {
                $this->backups->discard($key);

                return $this->redirectToRoute('admin_backup', [], 303);
            }
            if (trim((string) $request->request->get('confirm')) !== self::CONFIRM) {
                $error = sprintf('Per ripristinare scrivi %s', self::CONFIRM);
            } else {
                try {
                    $rows = $this->backups->restore($data);
                    $this->backups->discard($key);
                    // Utenti e password ora sono quelli del backup: logout vero (anche il cookie "ricordami")
                    // e si rientra con quelle credenziali.
                    $response = $security->logout(false);
                    $request->getSession()->getFlashBag()->add('success', sprintf('Backup ripristinato (%s righe): accedi con le credenziali del backup', number_format($rows, 0, ',', '.')));

                    return $response ?? $this->redirectToRoute('app_login', [], 303);
                } catch (\InvalidArgumentException|\RuntimeException $e) {
                    $error = $e->getMessage();
                }
            }
        }

        return $this->render('admin/backup_review.html.twig', [
            'key' => $key,
            's' => $this->backups->summary($data),
            'confirm' => self::CONFIRM,
            'error' => $error,
        ], new Response(status: $error ? 422 : 200));
    }
}
