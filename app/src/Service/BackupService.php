<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Backup completo dell'istanza: tutte le tabelle, utenti e password (gia' cifrate) compresi, in un file JSON.
 *
 * Il ripristino sostituisce tutto: svuota le tabelle e reinserisce le righe con i loro id, cosi' ogni
 * riferimento resta valido. Le tabelle si leggono dal database e non da un elenco: una tabella nuova
 * entra da sola nel backup. I valori restano quelli del database (stringhe, numeri come testo, null).
 */
final class BackupService
{
    public const FORMAT = 'gruzzolo-backup';
    public const VERSION = 1;

    /** Nomi da mostrare nell'anteprima; le tabelle non elencate compaiono col loro nome. */
    public const LABELS = [
        'app_user' => 'Utenti',
        'account' => 'Conti',
        'account_member' => 'Condivisioni dei conti',
        'account_home_hidden' => 'Conti nascosti dalla home',
        'category' => 'Categorie',
        'tag' => 'Etichette',
        'movement' => 'Movimenti',
        'movement_tag' => 'Etichette sui movimenti',
        'vehicle' => 'Veicoli',
        'refuel' => 'Rifornimenti',
        'holding' => 'Titoli',
        'investment_entry' => 'Aggiornamenti dei titoli',
        'import_batch' => 'Importazioni CSV',
    ];

    /** Lo schema lo danno le migration dell'app, non il backup. */
    private const SKIP = ['doctrine_migration_versions', 'messenger_messages'];

    private const CHUNK = 500;

    public function __construct(
        private readonly Connection $conn,
        #[Autowire('%kernel.project_dir%/var/backups')] private readonly string $dir,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @return list<string> */
    public function tables(): array
    {
        $tables = $this->conn->fetchFirstColumn(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name");

        return array_values(array_diff($tables, self::SKIP));
    }

    /** @return array<string, int> righe per tabella nel database */
    public function counts(): array
    {
        $out = [];
        foreach ($this->tables() as $table) {
            $out[$table] = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM '.$this->conn->quoteIdentifier($table));
        }

        return $out;
    }

    public function filename(): string
    {
        return 'gruzzolo-backup-'.date('Y-m-d-His').'.json';
    }

    /**
     * Scrive il backup su uno stream una tabella alla volta, senza tenere tutto in memoria.
     *
     * @param resource $out
     */
    public function write($out): void
    {
        $json = static fn (mixed $v): string => json_encode($v, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        fwrite($out, '{"format":'.$json(self::FORMAT).',"version":'.self::VERSION
            .',"created_at":'.$json(date(\DATE_ATOM)).',"migrations":'.$json($this->migrations()).',"tables":{');
        foreach ($this->tables() as $i => $table) {
            fwrite($out, ($i ? ',' : '')."\n".$json($table).':[');
            $result = $this->conn->executeQuery('SELECT * FROM '.$this->conn->quoteIdentifier($table));
            $first = true;
            while (($row = $result->fetchAssociative()) !== false) {
                fwrite($out, ($first ? "\n" : ",\n").$json($row));
                $first = false;
            }
            fwrite($out, ']');
        }
        fwrite($out, "\n}}\n");
    }

    /** Parcheggia il file caricato in var/backups (scartati dopo un giorno) e ne restituisce la chiave. */
    public function store(string $path): string
    {
        $this->load($path);
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0o777, true);
        }
        foreach (glob($this->dir.'/*.json') ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
        $key = bin2hex(random_bytes(8));
        if (!copy($path, $this->path($key))) {
            throw new \RuntimeException($this->translator->trans('Impossibile salvare il file caricato.'));
        }

        return $key;
    }

    public function exists(string $key): bool
    {
        return preg_match('/^[a-f0-9]{16}$/', $key) && is_file($this->path($key));
    }

    public function discard(string $key): void
    {
        if ($this->exists($key)) {
            @unlink($this->path($key));
        }
    }

    public function loadStored(string $key): array
    {
        return $this->load($this->path($key));
    }

    /**
     * Legge e decodifica un backup (anche compresso con gzip).
     *
     * @return array{format: string, version: int, created_at: string, migrations: list<string>, tables: array<string, list<array<string, ?string>>>}
     */
    public function load(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \InvalidArgumentException($this->translator->trans('File non leggibile.'));
        }
        if (str_starts_with($raw, "\x1f\x8b")) {
            $raw = @gzdecode($raw);
            if ($raw === false) {
                throw new \InvalidArgumentException($this->translator->trans('Archivio gzip danneggiato.'));
            }
        }
        try {
            $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException($this->translator->trans('Il file non è un backup di Gruzzolo (JSON non valido).'));
        }
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT || !is_array($data['tables'] ?? null)) {
            throw new \InvalidArgumentException($this->translator->trans('Il file non è un backup di Gruzzolo.'));
        }
        if (!is_int($data['version'] ?? null) || $data['version'] > self::VERSION) {
            throw new \InvalidArgumentException($this->translator->trans('Backup creato da una versione più recente di Gruzzolo: aggiorna l\'app prima di ripristinarlo.'));
        }
        $data['migrations'] = array_values(array_filter((array) ($data['migrations'] ?? []), 'is_string'));

        return $data;
    }

    /**
     * Cosa c'e' nel file rispetto al database, per l'anteprima.
     *
     * @return array{created_at: ?\DateTimeImmutable, rows: list<array{table: string, label: string, file: int, db: int}>, users: list<array{username: string, name: string, admin: bool, active: bool}>, older: bool, problems: list<string>}
     */
    public function summary(array $data): array
    {
        $db = $this->counts();
        $rows = [];
        foreach (array_unique([...array_keys(self::LABELS), ...array_keys($db), ...array_keys($data['tables'])]) as $table) {
            if (isset($db[$table]) || isset($data['tables'][$table])) {
                $rows[] = ['table' => $table, 'label' => isset(self::LABELS[$table]) ? $this->translator->trans(self::LABELS[$table]) : $table,
                    'file' => is_array($data['tables'][$table] ?? null) ? count($data['tables'][$table]) : 0, 'db' => $db[$table] ?? 0];
            }
        }
        $users = [];
        foreach ((array) ($data['tables']['app_user'] ?? []) as $u) {
            if (is_array($u)) {
                $users[] = ['username' => (string) ($u['username'] ?? ''), 'name' => (string) ($u['display_name'] ?? ''),
                    'admin' => $this->isAdminRow($u), 'active' => (bool) ($u['active'] ?? false)];
            }
        }

        return [
            'created_at' => is_string($data['created_at'] ?? null) ? (new \DateTimeImmutable($data['created_at']))->setTimezone(new \DateTimeZone(date_default_timezone_get())) : null,
            'rows' => $rows,
            'users' => $users,
            'older' => array_diff($this->migrations(), $data['migrations']) !== [],
            'problems' => $this->problems($data),
        ];
    }

    /**
     * Motivi per cui il backup non si puo' ripristinare in questo database (vuoto = tutto a posto).
     *
     * @return list<string>
     */
    public function problems(array $data): array
    {
        $problems = [];
        if (array_diff($data['migrations'], $this->migrations())) {
            $problems[] = $this->translator->trans('Il backup viene da una versione più recente di Gruzzolo: aggiorna l\'app prima di ripristinarlo.');
        }
        $tables = $this->tables();
        foreach ($data['tables'] as $table => $rows) {
            if (!in_array($table, $tables, true)) {
                $problems[] = $this->translator->trans('Tabella sconosciuta: %table%.', ['%table%' => $table]);
                continue;
            }
            if (!is_array($rows) || !array_is_list($rows)) {
                $problems[] = $this->translator->trans('Righe di %table% non valide.', ['%table%' => $table]);
                continue;
            }
            if (!$rows) {
                continue;
            }
            $columns = is_array($rows[0]) ? array_keys($rows[0]) : [];
            if ($unknown = array_diff($columns, $this->columns($table))) {
                $problems[] = $this->translator->trans('Colonne sconosciute in %table%: %columns%.', ['%table%' => $table, '%columns%' => implode(', ', $unknown)]);
            }
            foreach ($rows as $n => $row) {
                if (!is_array($row) || array_keys($row) !== $columns || array_filter($row, static fn ($v) => $v !== null && !is_scalar($v))) {
                    $problems[] = $this->translator->trans('Riga %line% di %table% non valida.', ['%line%' => $n + 1, '%table%' => $table]);
                    break;
                }
            }
        }
        // Il ripristino lo fa un amministratore: senza un admin attivo nel file nessuno potrebbe piu' rifarlo.
        $admins = array_filter((array) ($data['tables']['app_user'] ?? []), fn ($u) => is_array($u) && $this->isAdminRow($u) && ($u['active'] ?? false));
        if (!$admins) {
            $problems[] = $this->translator->trans('Nel backup non c\'è un amministratore attivo: dopo il ripristino nessuno potrebbe più accedere all\'amministrazione.');
        }

        return $problems;
    }

    /**
     * Sostituisce tutti i dati con quelli del backup, in una transazione: se qualcosa va storto resta tutto com'era.
     *
     * @return int righe ripristinate
     */
    public function restore(array $data): int
    {
        if ($problems = $this->problems($data)) {
            throw new \InvalidArgumentException(implode(' ', $problems));
        }
        $total = 0;
        // Con i vincoli spenti l'ordine di cancellazione e inserimento non conta (categorie figlie, conti condivisi...):
        // la coerenza dei riferimenti si controlla alla fine, prima del commit.
        $this->conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->conn->transactional(function () use ($data, &$total): void {
                foreach ($this->tables() as $table) {
                    $this->conn->executeStatement('DELETE FROM '.$this->conn->quoteIdentifier($table));
                }
                foreach ($data['tables'] as $table => $rows) {
                    foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                        $total += $this->insert($table, $chunk);
                    }
                }
                $this->checkReferences();
            });
        } catch (\Doctrine\DBAL\Exception $e) {
            throw new \RuntimeException($this->translator->trans('Ripristino non riuscito, i dati non sono stati toccati: %error%', ['%error%' => $e->getMessage()]), 0, $e);
        } finally {
            $this->conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $total;
    }

    /** @param non-empty-list<array<string, ?scalar>> $rows */
    private function insert(string $table, array $rows): int
    {
        $columns = array_map($this->conn->quoteIdentifier(...), array_keys($rows[0]));
        $placeholders = '('.implode(',', array_fill(0, count($columns), '?')).')';
        $params = [];
        foreach ($rows as $row) {
            array_push($params, ...array_values($row));
        }

        return $this->conn->executeStatement(sprintf('INSERT INTO %s (%s) VALUES %s', $this->conn->quoteIdentifier($table),
            implode(',', $columns), implode(',', array_fill(0, count($rows), $placeholders))), $params);
    }

    /** Ogni chiave esterna deve puntare a una riga esistente: un file ritoccato a mano non lascia orfani. */
    private function checkReferences(): void
    {
        $fks = $this->conn->fetchAllAssociative(
            'SELECT table_name AS t, column_name AS c, referenced_table_name AS rt, referenced_column_name AS rc
             FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND referenced_table_name IS NOT NULL');
        $broken = [];
        foreach ($fks as $fk) {
            [$t, $c, $rt, $rc] = array_map($this->conn->quoteIdentifier(...), [$fk['t'], $fk['c'], $fk['rt'], $fk['rc']]);
            $n = (int) $this->conn->fetchOne("SELECT COUNT(*) FROM $t x WHERE x.$c IS NOT NULL AND NOT EXISTS (SELECT 1 FROM $rt y WHERE y.$rc = x.$c)");
            if ($n) {
                $broken[] = $this->translator->trans('%column% (%count% riga)|%column% (%count% righe)', ['%column%' => $fk['t'].'.'.$fk['c'], '%count%' => $n]);
            }
        }
        if ($broken) {
            throw new \RuntimeException($this->translator->trans('Ripristino non riuscito, i dati non sono stati toccati: riferimenti a righe inesistenti in %references%.', ['%references%' => implode(', ', $broken)]));
        }
    }

    /** @return list<string> */
    private function migrations(): array
    {
        return $this->conn->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version');
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return $this->conn->fetchFirstColumn('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
    }

    private function isAdminRow(array $user): bool
    {
        return str_contains((string) ($user['roles'] ?? ''), 'ROLE_ADMIN');
    }

    private function path(string $key): string
    {
        return $this->dir.'/'.$key.'.json';
    }
}
