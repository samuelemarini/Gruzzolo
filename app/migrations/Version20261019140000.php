<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stile dell\'interfaccia per utente (classico o Liquid Glass)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_user ADD style VARCHAR(10) DEFAULT 'classic' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP style');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
