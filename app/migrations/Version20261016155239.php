<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Categorie per conto, parte 1: ogni categoria passa al conto principale del suo proprietario;
 * se un movimento usa una categoria di un altro conto, nel conto del movimento nasce una copia.
 */
final class Version20261016155239 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Categorie per conto (1/2): assegnazione ai conti e copie dove servono';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category ADD account_id INT DEFAULT NULL');
        // Conto predefinito (o il primo) del proprietario.
        $this->addSql(<<<'SQL'
            UPDATE category c SET c.account_id = (
                SELECT a.id FROM account a WHERE a.user_id = c.user_id
                ORDER BY a.is_default DESC, a.archived ASC, a.position ASC, a.id ASC LIMIT 1)
            SQL);
        // Utenti senza conti: le loro categorie non servono a nessun movimento.
        $this->addSql('DELETE FROM category WHERE account_id IS NULL');
    }

    public function postUp(Schema $schema): void
    {
        $db = $this->connection;
        $map = [];
        $copy = function (int $categoryId, int $accountId) use (&$copy, &$map, $db): int {
            $key = $accountId.'|'.$categoryId;
            if (isset($map[$key])) {
                return $map[$key];
            }
            $c = $db->fetchAssociative('SELECT * FROM category WHERE id = ?', [$categoryId]);
            if ((int) $c['account_id'] === $accountId) {
                return $map[$key] = $categoryId;
            }
            $parent = $c['parent_id'] ? $copy((int) $c['parent_id'], $accountId) : null;
            $existing = $db->fetchOne(
                'SELECT id FROM category WHERE account_id = ? AND name = ? AND kind = ? AND parent_id <=> ?',
                [$accountId, $c['name'], $c['kind'], $parent],
            );
            if ($existing) {
                return $map[$key] = (int) $existing;
            }
            $owner = $db->fetchOne('SELECT user_id FROM account WHERE id = ?', [$accountId]);
            $db->insert('category', [
                'user_id' => $owner, 'account_id' => $accountId, 'parent_id' => $parent, 'name' => $c['name'],
                'kind' => $c['kind'], 'icon' => $c['icon'], 'color' => $c['color'], 'fuel' => $c['fuel'],
                'archived' => $c['archived'], 'position' => $c['position'],
            ]);

            return $map[$key] = (int) $db->lastInsertId();
        };

        $pairs = $db->fetchAllAssociative(<<<'SQL'
            SELECT DISTINCT m.account_id AS account, m.category_id AS category
            FROM movement m JOIN category c ON c.id = m.category_id
            WHERE c.account_id <> m.account_id
            SQL);
        foreach ($pairs as $pair) {
            $new = $copy((int) $pair['category'], (int) $pair['account']);
            $db->executeStatement('UPDATE movement SET category_id = ? WHERE account_id = ? AND category_id = ?',
                [$new, $pair['account'], $pair['category']]);
        }
        $this->write(sprintf('Categorie copiate in altri conti: %d', count(array_filter($map, fn ($id, $k) => (int) explode('|', $k)[1] !== $id, ARRAY_FILTER_USE_BOTH))));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category DROP account_id');
    }
}
