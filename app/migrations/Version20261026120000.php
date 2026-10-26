<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Colore d\'accento e colore dell\'icona dell\'app per utente';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_user ADD accent VARCHAR(10) DEFAULT 'lime' NOT NULL, ADD icon_color VARCHAR(10) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP accent, DROP icon_color');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
