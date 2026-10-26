<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forma dell\'icona dell\'app per utente (anello o maialino)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_user ADD icon_shape VARCHAR(10) DEFAULT 'anello' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP icon_shape');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
