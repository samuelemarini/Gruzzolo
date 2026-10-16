<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand('app:user:create', 'Crea un utente con conto e categorie di partenza')]
final class UserCreateCommand
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserSeeder $seeder,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Nome utente per il login')] string $username,
        #[Argument('Nome visualizzato')] ?string $name = null,
        #[Option('Password (se omessa viene chiesta)')] ?string $password = null,
        #[Option('Amministratore: puo\' creare altri utenti')] bool $admin = false,
    ): int {
        if ($this->users->findOneByUsername($username)) {
            $io->error(sprintf('L\'utente "%s" esiste già.', $username));

            return Command::FAILURE;
        }
        $password ??= $io->askHidden('Password');
        if (!$password || mb_strlen($password) < 6) {
            $io->error('Serve una password di almeno 6 caratteri.');

            return Command::FAILURE;
        }

        $user = (new User())->setUsername($username)->setDisplayName($name ?? $username)->setAdmin($admin);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->seeder->seed($user);
        $this->em->flush();

        $io->success(sprintf('Creato l\'utente "%s"%s.', $user->getUsername(), $admin ? ' (amministratore)' : ''));

        return Command::SUCCESS;
    }
}
