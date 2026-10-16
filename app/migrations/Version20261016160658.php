<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016160658 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conti nascosti dalla home, per utente';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE account_home_hidden (account_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_A91E7C039B6B5FBA (account_id), INDEX IDX_A91E7C03A76ED395 (user_id), PRIMARY KEY (account_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE account_home_hidden ADD CONSTRAINT FK_A91E7C039B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE account_home_hidden ADD CONSTRAINT FK_A91E7C03A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account_home_hidden DROP FOREIGN KEY FK_A91E7C039B6B5FBA');
        $this->addSql('ALTER TABLE account_home_hidden DROP FOREIGN KEY FK_A91E7C03A76ED395');
        $this->addSql('DROP TABLE account_home_hidden');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
