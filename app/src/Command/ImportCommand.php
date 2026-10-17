<?php

namespace App\Command;

use App\Entity\ImportBatch;
use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use App\Repository\UserRepository;
use App\Import\ImportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Importa un CSV per conto di un utente, come dalla pagina Importa: tutti i wallet del file finiscono
 * nel conto indicato, le categorie si abbinano per nome a quelle del conto e le mancanti si creano.
 * Utile all'amministratore per importare nei conti degli altri (i movimenti risultano loro).
 */
#[AsCommand('app:import', 'Importa un CSV (Spendee, Wallet, Money Manager) nel conto di un utente')]
final class ImportCommand
{
    public function __construct(
        private readonly ImportService $imports,
        private readonly UserRepository $users,
        private readonly AccountRepository $accounts,
        private readonly CategoryRepository $categories,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Utente che importa (autore dei movimenti)')] string $username,
        #[Argument('Percorso del file CSV (con --undo non serve)')] ?string $file = null,
        #[Option('Id del conto in cui importare')] ?int $account = null,
        #[Option('Mostra cosa farebbe senza importare')] bool $dryRun = false,
        #[Option('Annulla l\'importazione con questo id (come dalla pagina Importa)')] ?int $undo = null,
    ): int {
        $user = $this->users->findOneByUsername($username);
        if ($undo) {
            $batch = $this->em->find(ImportBatch::class, $undo);
            if (!$batch || $batch->getUser() !== $user) {
                $io->error('Importazione non trovata per questo utente.');

                return Command::FAILURE;
            }
            $io->success(sprintf('Importazione #%d annullata: %d movimenti tolti.', $undo, $this->imports->undo($batch)));

            return Command::SUCCESS;
        }
        $target = $account ? $this->accounts->find($account) : null;
        if (!$user || !$target || !$target->isAccessibleBy($user) || !$file || !is_file($file)) {
            $io->error('Utente, conto (--account) o file non validi, oppure il conto non è dell\'utente.');

            return Command::FAILURE;
        }

        // store() sposta il file: si lavora su una copia.
        $copy = tempnam(sys_get_temp_dir(), 'imp');
        copy($file, $copy);
        try {
            $key = $this->imports->store($user, new UploadedFile($copy, basename($file), 'text/csv', null, true));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $analysis = $this->imports->analyze($user, $key);

        $categoryMap = [];
        $rows = [];
        foreach ($analysis['categories'] as $c) {
            $existing = $this->categories->findOneByName($target, $c['kind'], $c['name']);
            $categoryMap[$c['key']] = $existing ? (string) $existing->getId() : 'new';
            $rows[] = [$c['kind'] === 'income' ? 'entrata' : 'uscita', $c['name'], $c['count'], number_format($c['total'], 2, ',', '.'), $existing ? 'esistente' : 'nuova'];
        }

        $io->title(sprintf('%s → «%s» di %s', $analysis['format']->label(), $target->getName(), $user->getUsername()));
        $io->definitionList(
            ['Righe' => $analysis['total']],
            ['Da importare' => $analysis['new']],
            ['Già importate' => $analysis['duplicates']],
            ['Scartate' => count($analysis['errors'])],
            ['Entrate' => number_format($analysis['income'], 2, ',', '.')],
            ['Uscite' => number_format($analysis['expense'], 2, ',', '.')],
            ['Periodo' => ($analysis['first']?->format('d/m/Y') ?? '-').' – '.($analysis['last']?->format('d/m/Y') ?? '-')],
        );
        foreach (array_slice($analysis['errors'], 0, 10) as $error) {
            $io->writeln(sprintf(' <comment>riga %d</comment>: %s', $error['line'], $error['message']));
        }
        $io->table(['Tipo', 'Categoria', 'Movimenti', 'Totale', 'Nel conto'], $rows);

        if ($dryRun) {
            $this->imports->discard($user, $key);
            $io->note('Prova: nulla è stato importato.');

            return Command::SUCCESS;
        }

        $batch = $this->imports->import($user, $key, array_fill_keys(array_keys($analysis['wallets']), (string) $target->getId()), $categoryMap);
        $io->success(sprintf('Importazione #%d completata (annullabile dalla pagina Importa di %s).', $batch->getId(), $user->getUsername()));

        return Command::SUCCESS;
    }
}
