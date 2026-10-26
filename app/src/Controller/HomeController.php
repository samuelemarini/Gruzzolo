<?php

namespace App\Controller;

use App\Repository\AccountRepository;
use App\Repository\HoldingRepository;
use App\Repository\MovementRepository;
use App\Service\BalanceService;
use App\Service\InvestmentLive;
use App\Service\InvestmentStats;
use App\Service\Palette;
use App\Service\ReportService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AppController
{
    #[Route('/', name: 'app_home')]
    public function index(
        Request $request,
        ReportService $reports,
        MovementRepository $movements,
        AccountRepository $accounts,
        HoldingRepository $holdings,
        BalanceService $balances,
        InvestmentStats $investments,
        InvestmentLive $live,
    ): Response {
        $user = $this->me();
        $month = self::month($request);
        $from = $month;
        $to = $month->modify('last day of this month');

        // I conti esclusi dalla home restano nell'elenco col saldo, ma i loro movimenti non entrano nei
        // totali del mese ne' negli ultimi movimenti. Gli archiviati non sono in elenco ma contano, se non esclusi.
        $all = $accounts->findForUser($user, true);
        $onHome = array_filter($all, fn ($a) => $a->isOnHomeFor($user));
        $accountIds = count($onHome) === count($all) ? null : (array_values(array_map(fn ($a) => $a->getId(), $onHome)) ?: [0]);
        $accountList = array_values(array_filter($all, fn ($a) => !$a->isArchived()));

        $byCategory = $reports->byCategory($user, $from, $to, 'expense', $accountIds ?? []);
        $split = array_slice($byCategory, 0, 3);
        $rest = array_slice($byCategory, 3);
        if ($rest) {
            $split[] = ['name' => 'Altro', 'color' => '#5A5E5B', 'total' => array_sum(array_column($rest, 'total')),
                'pct' => array_sum(array_column($rest, 'pct')), 'icon' => 'circle-ellipsis', 'category' => null];
        }

        $holdingList = $holdings->findForUser($user);

        return $this->render('home/index.html.twig', [
            'month' => $month,
            'isCurrentMonth' => $month->format('Y-m') === date('Y-m'),
            'summary' => $reports->summary($user, $from, $to, $accountIds ?? []),
            'split' => $split,
            'latest' => $movements->latest($user, 6, $accountIds),
            // Per i link a Movimenti e Report: gli stessi conti dei totali della home.
            'scope' => ['account' => $accountIds ?? 'all'],
            'scopeIds' => $accountIds ?? array_map(fn ($a) => $a->getId(), $all),
            'accounts' => $accountList,
            'balances' => $balances->balances($user),
            'portfolio' => $portfolio = $holdingList ? $investments->portfolio($holdingList) : null,
            'portfolioLive' => $portfolio && $user->isLiveInvestments() ? $live->portfolio($portfolio) : null,
        ]);
    }

    #[Route('/altro', name: 'more_index')]
    public function more(): Response
    {
        return $this->render('more/index.html.twig');
    }

    /** Il manifest si scarica senza cookie: il colore dell'icona arriva nell'indirizzo (?colore=blu). */
    #[Route('/manifest.webmanifest', name: 'pwa_manifest')]
    public function manifest(Request $request): Response
    {
        $color = (string) $request->query->get('colore');
        $response = $this->render('pwa/manifest.json.twig', ['iconColor' => isset(Palette::ACCENTS[$color]) ? $color : Palette::APP_ICON]);
        $response->headers->set('Content-Type', 'application/manifest+json');

        return $response;
    }

    /**
     * Icona dell'app nel colore scelto, ricavata da assets/images/icon.svg (indaco col simbolo bianco):
     * negli altri colori, tutti chiari, il simbolo diventa scuro. L'anello sta gia' nella zona sicura
     * delle icone "maskable", quindi i PNG sono tutti uguali a parte la misura.
     */
    #[Route('/icona/{color}/{file}', name: 'pwa_icon', requirements: ['file' => 'icon(-180|-192|-512|-maskable-512)?\.(png|svg)'])]
    public function icon(string $color, string $file, #[Autowire('%kernel.project_dir%/assets/images')] string $dir): Response
    {
        if ($color !== Palette::APP_ICON && !isset(Palette::ACCENTS[$color])) {
            throw $this->createNotFoundException();
        }
        $svg = (string) file_get_contents($dir.'/icon.svg');
        if ($color !== Palette::APP_ICON) {
            $svg = strtr($svg, ['#4F46E5' => Palette::ACCENTS[$color][1], '#FFFFFF' => '#0D0E0C']);
        }
        if ($file === 'icon.svg') {
            $response = new Response($svg, 200, ['Content-Type' => 'image/svg+xml']);
        } else {
            if (!preg_match('/-(\d+)\.png$/', $file, $m) || !extension_loaded('imagick')) {
                return new BinaryFileResponse($dir.'/'.$file);
            }
            $size = (int) $m[1];
            $image = new \Imagick();
            // Disegnato a 4x e poi ridotto: l'antialiasing del renderer SVG di Imagick da solo lascia i bordi seghettati
            $image->readImageBlob(preg_replace('/(<svg[^>]*?) width="\d+" height="\d+"/', '$1 width="'.(4 * $size).'" height="'.(4 * $size).'"', $svg, 1));
            $image->resizeImage($size, $size, \Imagick::FILTER_LANCZOS, 1);
            $image->setImageFormat('png');
            $response = new Response($image->getImageBlob(), 200, ['Content-Type' => 'image/png']);
        }

        return $response->setPublic()->setMaxAge(7 * 86400);
    }

    /** Mese richiesto (?month=2026-10), primo giorno; default il mese corrente. */
    public static function month(Request $request): \DateTimeImmutable
    {
        $month = \DateTimeImmutable::createFromFormat('!Y-m', (string) $request->query->get('month', ''));

        return ($month ?: new \DateTimeImmutable('first day of this month'))->setTime(0, 0);
    }
}
