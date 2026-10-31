<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lingua dell\'interfaccia per utente (null = quella del browser)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD locale VARCHAR(5) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP locale');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
