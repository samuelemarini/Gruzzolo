<?php

namespace App\Import;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\ImportBatch;
use App\Entity\Movement;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use App\Repository\TagRepository;
use App\Service\Palette;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Importazione in tre passi: il file caricato si parcheggia in var/imports, si analizza (anteprima e
 * proposte di abbinamento) e poi si importa con le scelte dell'utente. Ogni importazione e' annullabile.
 */
final class ImportService
{
    /** Icona per le categorie nuove, dal nome. */
    private const ICON_HINTS = [
        'gelat' => 'ice-cream-cone', 'caff' => 'coffee', 'amazon' => 'package', 'pacc' => 'package', 'internet' => 'globe',
        'online' => 'globe', 'assicur' => 'shield', 'manuten' => 'wrench', 'ripar' => 'wrench', 'event' => 'party-popper',
        'fest' => 'party-popper', 'sport' => 'trophy', 'calcett' => 'trophy', 'palestr' => 'dumbbell', 'carbur' => 'fuel',
        'benzin' => 'fuel', 'mangi' => 'utensils', 'ristor' => 'utensils', 'cena' => 'utensils', 'pranz' => 'utensils',
        'bere' => 'beer', 'birr' => 'beer', 'pizz' => 'pizza', 'spesa' => 'shopping-cart', 'aliment' => 'shopping-cart',
        'regal' => 'gift', 'salut' => 'heart-pulse', 'medic' => 'stethoscope', 'farmac' => 'pill', 'vestit' => 'shirt',
        'abbigl' => 'shirt', 'viagg' => 'plane', 'vacanz' => 'tree-palm', 'hotel' => 'bed', 'casa' => 'house',
        'affitt' => 'key-round', 'bollett' => 'zap', 'luce' => 'zap', 'telefon' => 'smartphone', 'cellul' => 'smartphone',
        'abbonam' => 'repeat', 'stipend' => 'briefcase', 'entrat' => 'hand-coins', 'tass' => 'landmark', 'impost' => 'landmark',
        'auto' => 'car', 'macchin' => 'car', 'moto' => 'motorbike', 'treno' => 'train-front', 'bus' => 'bus',
        'parchegg' => 'circle-parking', 'animal' => 'paw-print', 'cane' => 'dog', 'gatt' => 'cat', 'libr' => 'book-open',
        'scuol' => 'graduation-cap', 'cinema' => 'film', 'film' => 'film', 'music' => 'music', 'gioc' => 'gamepad-2',
        'parrucc' => 'scissors', 'bellez' => 'sparkles', 'rimbors' => 'undo-2', 'sigar' => 'cigarette', 'tabacc' => 'cigarette',
        'invest' => 'trending-up', 'rispar' => 'piggy-bank',
    ];

    private const COLORS = ['#8AB4FF', '#FFB86B', '#FF8A80', '#6FDCCB', '#C3A6FF', '#F3E06A', '#FF9EC7', '#7FD6FF', '#9BE7A0', '#D9B48F'];

    /** @param iterable<ImportFormat> $formats */
    public function __construct(
        #[AutowireIterator('app.import_format')] private readonly iterable $formats,
        #[Autowire('%kernel.project_dir%/var/imports')] private readonly string $dir,
        private readonly EntityManagerInterface $em,
        private readonly AccountRepository $accounts,
        private readonly CategoryRepository $categories,
        private readonly TagRepository $tags,
    ) {
    }

    /** @return list<string> nomi dei formati supportati */
    public function formatLabels(): array
    {
        return array_map(fn (ImportFormat $f) => $f->label(), iterator_to_array($this->formats, false));
    }

    /** Parcheggia il file caricato e restituisce la chiave per i passi successivi. */
    public function store(User $user, UploadedFile $file): string
    {
        $header = CsvReader::header($file->getPathname());
        if (!$this->detect($header)) {
            throw new \InvalidArgumentException('Formato non riconosciuto. Intestazione trovata: '.implode(', ', array_slice($header, 0, 10)));
        }
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
        $key = bin2hex(random_bytes(8));
        $file->move($this->dir, $this->path($user, $key, 'csv', true));
        file_put_contents($this->path($user, $key, 'json'), json_encode(['filename' => $file->getClientOriginalName()]));

        return $key;
    }

    public function exists(User $user, string $key): bool
    {
        return preg_match('/^[a-f0-9]{16}$/', $key) && is_file($this->path($user, $key, 'csv'));
    }

    public function discard(User $user, string $key): void
    {
        if ($this->exists($user, $key)) {
            @unlink($this->path($user, $key, 'csv'));
            @unlink($this->path($user, $key, 'json'));
        }
    }

    /**
     * Legge il file e prepara l'anteprima: righe valide, errori, doppioni, conti e categorie da abbinare.
     *
     * @return array<string, mixed>
     */
    public function analyze(User $user, string $key): array
    {
        $path = $this->path($user, $key, 'csv');
        $format = $this->detect(CsvReader::header($path));
        $meta = json_decode((string) file_get_contents($this->path($user, $key, 'json')), true) ?: [];

        $rows = [];
        $errors = [];
        foreach (CsvReader::rows($path) as $line => $data) {
            if (is_string($data)) {
                $errors[] = ['line' => $line, 'message' => $data];
                continue;
            }
            try {
                $row = $format->parse($data, $line);
                if ($row->currency !== 'EUR') {
                    throw new \InvalidArgumentException(sprintf('valuta %s non supportata', $row->currency));
                }
                $rows[] = $row;
            } catch (\InvalidArgumentException $e) {
                $errors[] = ['line' => $line, 'message' => $e->getMessage()];
            }
        }
        $rows = $format->finalize($rows);

        // Impronte: la stessa riga ripetuta identica nel file conta come righe distinte (1a, 2a...).
        $seen = [];
        $hashes = [];
        foreach ($rows as $i => $row) {
            $n = $seen[$row->fingerprint] = ($seen[$row->fingerprint] ?? 0) + 1;
            $hashes[$i] = sha1($format->name().'|'.$row->fingerprint.'|'.$n);
        }
        $existing = $this->existingHashes($user, $hashes);

        $wallets = [];
        $cats = [];
        $tagNames = [];
        $income = $expense = 0.0;
        $transfers = 0;
        $first = $last = null;
        foreach ($rows as $i => $row) {
            if (isset($existing[$hashes[$i]])) {
                continue;
            }
            $wallets[$row->wallet] = ($wallets[$row->wallet] ?? 0) + 1;
            if ($row->toWallet !== null) {
                $wallets[$row->toWallet] = ($wallets[$row->toWallet] ?? 0) + 1;
            }
            if ($row->kind === 'transfer') {
                ++$transfers;
            } else {
                $k = $row->categoryKey();
                $cats[$k] ??= ['key' => $k, 'kind' => $row->kind, 'name' => $row->category ?? 'Senza categoria', 'count' => 0, 'total' => 0.0];
                ++$cats[$k]['count'];
                $cats[$k]['total'] += (float) $row->amount;
                $row->kind === 'income' ? $income += (float) $row->amount : $expense += (float) $row->amount;
            }
            foreach ($row->tags as $t) {
                $tagNames[Tag::normalize($t)] = true;
            }
            $first = !$first || $row->date < $first ? $row->date : $first;
            $last = !$last || $row->date > $last ? $row->date : $last;
        }
        uasort($cats, fn ($a, $b) => [$a['kind'], -$a['count']] <=> [$b['kind'], -$b['count']]);
        $new = count($rows) - count(array_intersect_key(array_flip($hashes), $existing));

        return [
            'key' => $key,
            'filename' => $meta['filename'] ?? 'file.csv',
            'format' => $format,
            'rows' => $rows,
            'hashes' => $hashes,
            'existing' => $existing,
            'errors' => $errors,
            'total' => count($rows),
            'new' => $new,
            'duplicates' => count($rows) - $new,
            'transfers' => $transfers,
            'income' => $income,
            'expense' => $expense,
            'first' => $first,
            'last' => $last,
            'wallets' => $wallets,
            'categories' => array_values($cats),
            'tags' => array_keys(array_filter($tagNames, fn ($v, $k) => $k !== '', ARRAY_FILTER_USE_BOTH)),
            'suggestions' => [
                'accounts' => $this->suggestAccounts($user, array_keys($wallets)),
                'categories' => $this->suggestCategories($user, $cats),
            ],
        ];
    }

    /**
     * Esegue l'importazione con gli abbinamenti scelti.
     *
     * @param array<string, string> $accountMap  wallet => id conto | 'new'
     * @param array<string, string> $categoryMap chiave categoria => id | 'new' | 'skip'
     */
    public function import(User $user, string $key, array $accountMap, array $categoryMap): ImportBatch
    {
        $analysis = $this->analyze($user, $key);
        $batch = new ImportBatch($user, $analysis['filename'], $analysis['format']->name());
        $this->em->persist($batch);
        $created = ['categories' => [], 'accounts' => [], 'tags' => []];
        $newAccounts = $newCategories = $newTags = [];

        $account = function (string $wallet) use ($user, $accountMap, &$newAccounts, &$created): ?Account {
            $choice = $accountMap[$wallet] ?? 'new';
            if ($choice !== 'new') {
                $found = $this->accounts->find((int) $choice);

                return $found && $found->getUser() === $user ? $found : null;
            }
            if (!isset($newAccounts[$wallet])) {
                $newAccounts[$wallet] = (new Account($user))->setName($wallet)->setKind('checking')
                    ->setColor(self::COLORS[count($newAccounts) % count(self::COLORS)])->setPosition(10 + count($newAccounts));
                $this->em->persist($newAccounts[$wallet]);
                $created['accounts'][] = $newAccounts[$wallet];
            }

            return $newAccounts[$wallet];
        };
        $category = function (ImportRow $row) use ($user, $categoryMap, &$newCategories, &$created): Category|false|null {
            $choice = $categoryMap[$row->categoryKey()] ?? 'new';
            if ($choice === 'skip') {
                return false;
            }
            if ($choice !== 'new') {
                $found = $this->categories->find((int) $choice);

                return $found && $found->getUser() === $user && $found->getKind() === $row->kind ? $found : null;
            }
            $k = $row->categoryKey();
            if (!isset($newCategories[$k])) {
                $name = $row->category ?? 'Importate';
                $newCategories[$k] = (new Category($user))->setName(mb_substr($name, 0, 60))->setKind($row->kind)
                    ->setIcon($this->guessIcon($name))->setColor(self::COLORS[count($newCategories) % count(self::COLORS)])
                    ->setPosition($this->categories->nextPosition($user, null) + count($newCategories));
                $this->em->persist($newCategories[$k]);
                $created['categories'][] = $newCategories[$k];
            }

            return $newCategories[$k];
        };
        $tag = function (string $name) use ($user, &$newTags, &$created): ?Tag {
            $name = Tag::normalize($name);
            if ($name === '') {
                return null;
            }
            if ($existing = $this->tags->findOneByName($user, $name)) {
                return $existing;
            }
            if (!isset($newTags[$name])) {
                $newTags[$name] = new Tag($user, $name);
                $this->em->persist($newTags[$name]);
                $created['tags'][] = $newTags[$name];
            }

            return $newTags[$name];
        };

        $imported = 0;
        $first = $last = null;
        foreach ($analysis['rows'] as $i => $row) {
            $hash = $analysis['hashes'][$i];
            if (isset($analysis['existing'][$hash])) {
                continue;
            }
            $from = $account($row->wallet);
            if (!$from) {
                continue;
            }
            $m = (new Movement($user, $from))->setKind($row->kind)->setAmount($row->amount)
                ->setDate($row->date->setTime(0, 0))->setDescription($row->description);
            if ($row->kind === 'transfer') {
                $to = $account((string) $row->toWallet);
                if (!$to || $to === $from) {
                    continue;
                }
                $m->setToAccount($to);
            } else {
                $cat = $category($row);
                if ($cat === false) {
                    continue;
                }
                $m->setCategory($cat);
            }
            foreach ($row->tags as $name) {
                if ($t = $tag($name)) {
                    $m->addTag($t);
                }
            }
            $m->setImported($batch, $hash);
            $this->em->persist($m);
            ++$imported;
            $first = !$first || $row->date < $first ? $row->date : $first;
            $last = !$last || $row->date > $last ? $row->date : $last;
            if ($imported % 300 === 0) {
                $this->em->flush();
            }
        }

        $batch->setImported($imported)->setSkipped($analysis['total'] - $imported + count($analysis['errors']))
            ->setRange($first?->setTime(0, 0), $last?->setTime(0, 0));
        $this->em->flush();
        $batch->setCreated(array_map(fn ($list) => array_map(fn ($e) => $e->getId(), $list), $created));
        $this->em->flush();
        $this->discard($user, $key);

        return $batch;
    }

    /** Annulla un'importazione: via i suoi movimenti e le cose create apposta, se non servono ad altro. */
    public function undo(ImportBatch $batch): int
    {
        $conn = $this->em->getConnection();
        $deleted = (int) $conn->executeStatement('DELETE FROM movement WHERE import_batch_id = ?', [$batch->getId()]);
        $created = $batch->getCreated();
        foreach ($created['tags'] as $id) {
            $conn->executeStatement('DELETE FROM tag WHERE id = ? AND NOT EXISTS (SELECT 1 FROM movement_tag WHERE tag_id = ?)', [$id, $id]);
        }
        foreach ($created['categories'] as $id) {
            $conn->executeStatement('DELETE FROM category WHERE id = ? AND NOT EXISTS (SELECT 1 FROM movement WHERE category_id = ?)
                AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM category WHERE parent_id = ?) c)', [$id, $id, $id]);
        }
        foreach ($created['accounts'] as $id) {
            $conn->executeStatement('DELETE FROM account WHERE id = ? AND NOT EXISTS (SELECT 1 FROM movement WHERE account_id = ? OR to_account_id = ?)', [$id, $id, $id]);
        }
        $this->em->remove($batch);
        $this->em->flush();

        return $deleted;
    }

    /** @param list<string> $header */
    private function detect(array $header): ?ImportFormat
    {
        foreach ($this->formats as $format) {
            if ($format->supports($header)) {
                return $format;
            }
        }

        return null;
    }

    /** @return array<string, true> */
    private function existingHashes(User $user, array $hashes): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique($hashes)), 1000) as $chunk) {
            $rows = $this->em->getConnection()->fetchFirstColumn(
                'SELECT import_hash FROM movement WHERE user_id = ? AND import_hash IN (?)',
                [$user->getId(), $chunk],
                [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::STRING],
            );
            foreach ($rows as $h) {
                $found[$h] = true;
            }
        }

        return $found;
    }

    /** @return array<string, string> wallet => id conto proposto | 'new' */
    private function suggestAccounts(User $user, array $wallets): array
    {
        $accounts = $this->accounts->findForUser($user);
        $out = [];
        foreach ($wallets as $wallet) {
            $match = array_values(array_filter($accounts, fn (Account $a) => self::norm($a->getName()) === self::norm($wallet)));
            // Con un solo wallet nel file, quasi sempre e' il conto principale.
            $out[$wallet] = $match ? (string) $match[0]->getId()
                : (count($wallets) === 1 && $accounts ? (string) $accounts[0]->getId() : 'new');
        }

        return $out;
    }

    /** @return array<string, string> chiave => id categoria proposta | 'new' */
    private function suggestCategories(User $user, array $cats): array
    {
        $all = $this->categories->findBy(['user' => $user, 'archived' => false]);
        $out = [];
        foreach ($cats as $key => $c) {
            // Solo stesso nome (senza badare a maiuscole e accenti): le categorie che non ci sono si creano.
            $name = self::norm($c['name']);
            $choice = 'new';
            foreach ($all as $category) {
                if ($category->getKind() !== $c['kind']) {
                    continue;
                }
                $n = self::norm($category->getName());
                if ($n === $name) {
                    $choice = (string) $category->getId();
                    break;
                }
            }
            $out[$key] = $choice;
        }

        return $out;
    }

    private function guessIcon(string $name): string
    {
        $n = self::norm($name);
        foreach (self::ICON_HINTS as $hint => $icon) {
            if (str_contains($n, $hint) && in_array($icon, Palette::CATEGORY_ICONS, true)) {
                return $icon;
            }
        }

        return 'circle-ellipsis';
    }

    /** "Caffè & Bar" → "caffe bar": confronto senza accenti, maiuscole e simboli. */
    private static function norm(string $text): string
    {
        $text = \Normalizer::normalize(mb_strtolower($text), \Normalizer::FORM_D) ?: $text;
        $text = preg_replace('/\p{Mn}+/u', '', $text) ?? $text;

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '');
    }

    private function path(User $user, string $key, string $ext, bool $nameOnly = false): string
    {
        $name = sprintf('%d-%s.%s', $user->getId(), $key, $ext);

        return $nameOnly ? $name : $this->dir.'/'.$name;
    }
}
