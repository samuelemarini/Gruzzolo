<?php

namespace App\Command;

use App\Service\BackupService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Come "Scarica il backup" dalla pagina Altro › Backup; con un nome che finisce in .gz il file esce compresso. */
#[AsCommand('app:backup:export', 'Esporta tutti i dati dell\'app (tutti gli utenti) in un file JSON')]
final class BackupExportCommand
{
    public function __construct(private readonly BackupService $backups)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('File da scrivere (.json o .json.gz); "-" per lo standard output')] ?string $file = null,
    ): int {
        $file ??= $this->backups->filename();
        $out = $file === '-' ? fopen('php://stdout', 'w') : @fopen((str_ends_with($file, '.gz') ? 'compress.zlib://' : '').$file, 'w');
        if (!$out) {
            $io->error(sprintf('Impossibile scrivere %s.', $file));

            return Command::FAILURE;
        }
        $this->backups->write($out);
        fclose($out);
        if ($file !== '-') {
            $io->success(sprintf('Backup scritto in %s (%s KB).', $file, number_format(filesize($file) / 1024, 0, ',', '.')));
        }

        return Command::SUCCESS;
    }
}
