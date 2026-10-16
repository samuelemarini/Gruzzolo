<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016140022 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE import_batch (id INT AUTO_INCREMENT NOT NULL, filename VARCHAR(255) NOT NULL, format VARCHAR(30) NOT NULL, imported INT NOT NULL, skipped INT NOT NULL, first_date DATE DEFAULT NULL, last_date DATE DEFAULT NULL, created JSON NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_155EFD1AA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE import_batch ADD CONSTRAINT FK_155EFD1AA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE movement ADD import_hash VARCHAR(40) DEFAULT NULL, ADD import_batch_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE movement ADD CONSTRAINT FK_F4DD95F75A310080 FOREIGN KEY (import_batch_id) REFERENCES import_batch (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_movement_user_import ON movement (user_id, import_hash)');
        $this->addSql('CREATE INDEX IDX_F4DD95F75A310080 ON movement (import_batch_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE import_batch DROP FOREIGN KEY FK_155EFD1AA76ED395');
        $this->addSql('DROP TABLE import_batch');
        $this->addSql('ALTER TABLE movement DROP FOREIGN KEY FK_F4DD95F75A310080');
        $this->addSql('DROP INDEX idx_movement_user_import ON movement');
        $this->addSql('DROP INDEX IDX_F4DD95F75A310080 ON movement');
        $this->addSql('ALTER TABLE movement DROP import_hash, DROP import_batch_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
