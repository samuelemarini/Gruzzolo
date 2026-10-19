<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Titoli esclusi dal rendimento del portafoglio';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE holding ADD excluded_from_returns TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE holding DROP excluded_from_returns');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
