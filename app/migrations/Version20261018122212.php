<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018122212 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Simbolo per il prezzo di mercato dei titoli';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE holding ADD quote_symbol VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE holding DROP quote_symbol');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
