<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Categorie per conto, parte 2: il conto diventa obbligatorio, il proprietario si ricava dal conto. */
final class Version20261016155240 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Categorie per conto (2/2): account_id obbligatorio, via user_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category DROP FOREIGN KEY `FK_64C19C1A76ED395`');
        $this->addSql('DROP INDEX IDX_64C19C1A76ED395 ON category');
        $this->addSql('ALTER TABLE category DROP user_id, CHANGE account_id account_id INT NOT NULL');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C19B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_64C19C19B6B5FBA ON category (account_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category DROP FOREIGN KEY FK_64C19C19B6B5FBA');
        $this->addSql('DROP INDEX IDX_64C19C19B6B5FBA ON category');
        $this->addSql('ALTER TABLE category ADD user_id INT DEFAULT NULL, CHANGE account_id account_id INT DEFAULT NULL');
        $this->addSql('UPDATE category c JOIN account a ON a.id = c.account_id SET c.user_id = a.user_id');
        $this->addSql('ALTER TABLE category CHANGE user_id user_id INT NOT NULL');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT `FK_64C19C1A76ED395` FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_64C19C1A76ED395 ON category (user_id)');
    }
}
