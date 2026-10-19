<?php

namespace App\Command;

use App\Service\BackupService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Come "Ripristina da un backup" dalla pagina Altro › Backup: sostituisce tutti i dati con quelli del file. */
#[AsCommand('app:backup:restore', 'Sostituisce tutti i dati dell\'app con quelli di un backup')]
final class BackupRestoreCommand
{
    public function __construct(private readonly BackupService $backups)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('File di backup (.json o .json.gz)')] string $file,
        #[Option('Non chiedere conferma')] bool $force = false,
    ): int {
        try {
            $data = $this->backups->load($file);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $s = $this->backups->summary($data);
        $io->title('Backup'.($s['created_at'] ? ' del '.$s['created_at']->format('d/m/Y H:i') : ''));
        $io->table(['Dati', 'Nel file', 'Ora'], array_map(fn ($r) => [$r['label'], $r['file'], $r['db']], $s['rows']));
        $io->text('Utenti: '.implode(', ', array_map(fn ($u) => $u['username'].($u['admin'] ? ' (admin)' : ''), $s['users'])));
        if ($s['problems']) {
            $io->error($s['problems']);

            return Command::FAILURE;
        }
        if (!$force && !$io->confirm('Tutti i dati attuali verranno sostituiti da quelli del file. Procedo?', false)) {
            return Command::SUCCESS;
        }
        try {
            $rows = $this->backups->restore($data);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(sprintf('Backup ripristinato: %d righe.', $rows));

        return Command::SUCCESS;
    }
}
