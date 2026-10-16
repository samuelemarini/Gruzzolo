<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016103343 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE account (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, kind VARCHAR(20) NOT NULL, color VARCHAR(7) NOT NULL, initial_balance NUMERIC(12, 2) DEFAULT \'0.00\' NOT NULL, is_default TINYINT DEFAULT 0 NOT NULL, archived TINYINT DEFAULT 0 NOT NULL, position INT DEFAULT 0 NOT NULL, user_id INT NOT NULL, INDEX IDX_7D3656A4A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(180) NOT NULL, display_name VARCHAR(100) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, theme VARCHAR(10) DEFAULT \'system\' NOT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_user_username (username), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(60) NOT NULL, kind VARCHAR(10) NOT NULL, icon VARCHAR(40) NOT NULL, color VARCHAR(7) NOT NULL, fuel TINYINT DEFAULT 0 NOT NULL, archived TINYINT DEFAULT 0 NOT NULL, position INT DEFAULT 0 NOT NULL, user_id INT NOT NULL, parent_id INT DEFAULT NULL, INDEX IDX_64C19C1A76ED395 (user_id), INDEX IDX_64C19C1727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE holding (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, symbol VARCHAR(40) DEFAULT NULL, kind VARCHAR(20) NOT NULL, color VARCHAR(7) NOT NULL, note LONGTEXT DEFAULT NULL, archived TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_5BBFD816A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE investment_entry (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, contribution NUMERIC(14, 2) DEFAULT \'0.00\' NOT NULL, value NUMERIC(14, 2) NOT NULL, note VARCHAR(255) DEFAULT NULL, holding_id INT NOT NULL, movement_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_44E2AB84229E70A7 (movement_id), INDEX IDX_44E2AB846CD5FBA3 (holding_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE movement (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(10) NOT NULL, amount NUMERIC(12, 2) NOT NULL, date DATE NOT NULL, description VARCHAR(160) NOT NULL, note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, account_id INT NOT NULL, to_account_id INT DEFAULT NULL, category_id INT DEFAULT NULL, vehicle_id INT DEFAULT NULL, INDEX idx_movement_user_date (user_id, date), INDEX IDX_F4DD95F7A76ED395 (user_id), INDEX IDX_F4DD95F79B6B5FBA (account_id), INDEX IDX_F4DD95F7BC58BDC7 (to_account_id), INDEX IDX_F4DD95F712469DE2 (category_id), INDEX IDX_F4DD95F7545317D1 (vehicle_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE movement_tag (movement_id INT NOT NULL, tag_id INT NOT NULL, INDEX IDX_20CD772B229E70A7 (movement_id), INDEX IDX_20CD772BBAD26311 (tag_id), PRIMARY KEY (movement_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE refuel (id INT AUTO_INCREMENT NOT NULL, odometer INT NOT NULL, liters NUMERIC(8, 2) NOT NULL, price_per_liter NUMERIC(6, 3) NOT NULL, full_tank TINYINT DEFAULT 1 NOT NULL, missed_previous TINYINT DEFAULT 0 NOT NULL, movement_id INT NOT NULL, UNIQUE INDEX UNIQ_B60345A1229E70A7 (movement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tag (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(60) NOT NULL, title VARCHAR(120) DEFAULT NULL, color VARCHAR(7) NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX uniq_tag_user_name (user_id, name), INDEX IDX_389B783A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vehicle (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, kind VARCHAR(20) NOT NULL, fuel_type VARCHAR(20) NOT NULL, plate VARCHAR(20) DEFAULT NULL, initial_odometer INT DEFAULT NULL, color VARCHAR(7) NOT NULL, archived TINYINT DEFAULT 0 NOT NULL, user_id INT NOT NULL, INDEX IDX_1B80E486A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE account ADD CONSTRAINT FK_7D3656A4A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C1A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C1727ACA70 FOREIGN KEY (parent_id) REFERENCES category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE holding ADD CONSTRAINT FK_5BBFD816A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE investment_entry ADD CONSTRAINT FK_44E2AB846CD5FBA3 FOREIGN KEY (holding_id) REFERENCES holding (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE investment_entry ADD CONSTRAINT FK_44E2AB84229E70A7 FOREIGN KEY (movement_id) REFERENCES movement (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE movement ADD CONSTRAINT FK_F4DD95F7A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE movement ADD CONSTRAINT FK_F4DD95F79B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id)');
        $this->addSql('ALTER TABLE movement ADD CONSTRAINT FK_F4DD95F7BC58BDC7 FOREIGN KEY (to_account_id) REFERENCES account (id)');
        $this->addSql('ALTER TABLE movement ADD CONSTRAINT FK_F4DD95F712469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE movement ADD CONSTRAINT FK_F4DD95F7545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicle (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE movement_tag ADD CONSTRAINT FK_20CD772B229E70A7 FOREIGN KEY (movement_id) REFERENCES movement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE movement_tag ADD CONSTRAINT FK_20CD772BBAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE refuel ADD CONSTRAINT FK_B60345A1229E70A7 FOREIGN KEY (movement_id) REFERENCES movement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tag ADD CONSTRAINT FK_389B783A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vehicle ADD CONSTRAINT FK_1B80E486A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account DROP FOREIGN KEY FK_7D3656A4A76ED395');
        $this->addSql('ALTER TABLE category DROP FOREIGN KEY FK_64C19C1A76ED395');
        $this->addSql('ALTER TABLE category DROP FOREIGN KEY FK_64C19C1727ACA70');
        $this->addSql('ALTER TABLE holding DROP FOREIGN KEY FK_5BBFD816A76ED395');
        $this->addSql('ALTER TABLE investment_entry DROP FOREIGN KEY FK_44E2AB846CD5FBA3');
        $this->addSql('ALTER TABLE investment_entry DROP FOREIGN KEY FK_44E2AB84229E70A7');
        $this->addSql('ALTER TABLE movement DROP FOREIGN KEY FK_F4DD95F7A76ED395');
        $this->addSql('ALTER TABLE movement DROP FOREIGN KEY FK_F4DD95F79B6B5FBA');
        $this->addSql('ALTER TABLE movement DROP FOREIGN KEY FK_F4DD95F7BC58BDC7');
        $this->addSql('ALTER TABLE movement DROP FOREIGN KEY FK_F4DD95F712469DE2');
        $this->addSql('ALTER TABLE movement DROP FOREIGN KEY FK_F4DD95F7545317D1');
        $this->addSql('ALTER TABLE movement_tag DROP FOREIGN KEY FK_20CD772B229E70A7');
        $this->addSql('ALTER TABLE movement_tag DROP FOREIGN KEY FK_20CD772BBAD26311');
        $this->addSql('ALTER TABLE refuel DROP FOREIGN KEY FK_B60345A1229E70A7');
        $this->addSql('ALTER TABLE tag DROP FOREIGN KEY FK_389B783A76ED395');
        $this->addSql('ALTER TABLE vehicle DROP FOREIGN KEY FK_1B80E486A76ED395');
        $this->addSql('DROP TABLE account');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE holding');
        $this->addSql('DROP TABLE investment_entry');
        $this->addSql('DROP TABLE movement');
        $this->addSql('DROP TABLE movement_tag');
        $this->addSql('DROP TABLE refuel');
        $this->addSql('DROP TABLE tag');
        $this->addSql('DROP TABLE vehicle');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
