<?php

namespace App\Command;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Holding;
use App\Entity\InvestmentEntry;
use App\Entity\Movement;
use App\Entity\Refuel;
use App\Entity\Tag;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Repository\UserRepository;
use App\Service\UserSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Utente dimostrativo con sei mesi di dati verosimili, per provare l'app e fare screenshot. */
#[AsCommand('app:demo', 'Crea (o ricrea) un utente demo con dati di esempio')]
final class DemoCommand
{
    /** @var array<string, Category> */
    private array $cat = [];
    /** @var array<string, Tag> */
    private array $tags = [];
    private User $user;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserSeeder $seeder,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.environment%')] private readonly string $env,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Nome utente')] string $username = 'demo',
        #[Option('Password')] string $password = 'demo1234',
        #[Option('Consenti anche in produzione')] bool $force = false,
    ): int {
        if ($this->env === 'prod' && !$force) {
            $io->error('In produzione serve --force.');

            return Command::FAILURE;
        }
        if ($old = $this->users->findOneByUsername($username)) {
            $this->em->getConnection()->executeStatement('DELETE FROM movement WHERE user_id = ?', [$old->getId()]);
            $this->em->remove($old);
            $this->em->flush();
        }
        mt_srand(42);

        $this->user = (new User())->setUsername($username)->setDisplayName('Demo');
        $this->user->setPassword($this->hasher->hashPassword($this->user, $password));
        $this->em->persist($this->user);
        $this->seeder->seed($this->user);
        $this->em->flush();

        foreach ($this->em->getRepository(Category::class)->findBy(['user' => $this->user]) as $c) {
            $this->cat[$c->getName()] = $c;
        }
        $main = $this->em->getRepository(Account::class)->findOneBy(['user' => $this->user]);
        $main->setInitialBalance('6800.00');
        $savings = (new Account($this->user))->setName('Accantonamento')->setKind('savings')->setColor('#C3A6FF')->setInitialBalance('900.00')->setPosition(1);
        $cash = (new Account($this->user))->setName('Contanti')->setKind('cash')->setColor('#9BE7A0')->setInitialBalance('450.00')->setPosition(2);
        $this->em->persist($savings);
        $this->em->persist($cash);

        $panda = (new Vehicle($this->user))->setName('Fiat Panda')->setKind('car')->setFuelType('petrol')->setPlate('GH482KL')->setInitialOdometer(41500)->setColor('#6FDCCB');
        $sh = (new Vehicle($this->user))->setName('Honda SH 125')->setKind('scooter')->setFuelType('petrol')->setPlate('EX19302')->setInitialOdometer(11200)->setColor('#FFB86B');
        $this->em->persist($panda);
        $this->em->persist($sh);

        $today = new \DateTimeImmutable('today');
        $start = $today->modify('first day of -5 months');

        for ($month = $start; $month <= $today; $month = $month->modify('+1 month')) {
            $d = fn (int $day) => $month->setDate((int) $month->format('Y'), (int) $month->format('m'), min($day, (int) $month->format('t')));
            $this->add($main, 'income', 'Stipendio', $d(27), $month->format('m') === '07' ? 2380 : 2150, 'Stipendio');
            $this->add($main, 'expense', 'Affitto o mutuo', $d(1), 650, 'Affitto');
            $this->add($main, 'expense', 'Bollette', $d(6), mt_rand(7000, 12500) / 100, mt_rand(0, 1) ? 'Luce e gas' : 'Enel Energia');
            $this->add($main, 'expense', 'Abbonamenti', $d(3), 10.99, 'Spotify');
            $this->add($main, 'expense', 'Abbonamenti', $d(12), 13.99, 'Netflix');
            $this->transfer($main, $savings, $d(28), 150, 'Accantonamento mensile');

            foreach ([2, 5, 9, 13, 16, 20, 23, 27, 30] as $day) {
                $shops = ['Esselunga', 'Coop', 'Lidl', 'Esselunga', 'Mercato'];
                $this->add($main, 'expense', 'Spesa', $d($day), mt_rand(1800, 9200) / 100, $shops[mt_rand(0, 4)]);
            }
            foreach ([4, 8, 11, 18, 22, 25] as $day) {
                $this->add($cash, 'expense', 'Ristoranti e bar', $d($day), mt_rand(150, 520) / 100, 'Bar Centrale');
            }
            $this->add($main, 'expense', 'Ristoranti e bar', $d(14), mt_rand(2500, 4800) / 100, 'Pizzeria Da Gino', ['cena-amici']);
            $this->add($main, 'expense', 'Ristoranti e bar', $d(21), mt_rand(3000, 5500) / 100, 'Sushi Zen');
            $this->add($main, 'expense', 'Svago', $d(17), mt_rand(900, 1800) / 100, 'Cinema Anteo');
            if (mt_rand(0, 1)) {
                $this->add($main, 'expense', 'Abbigliamento', $d(19), mt_rand(2500, 9000) / 100, 'Zalando');
            }
            $this->add($main, 'expense', 'Pedaggi e parcheggi', $d(10), mt_rand(250, 1400) / 100, 'Telepass', [], $panda);
            $this->add($main, 'expense', 'Salute', $d(15), mt_rand(800, 3500) / 100, 'Farmacia');
        }

        // Spese annuali pagate dal conto accantonamento, collegate all'auto.
        $this->add($savings, 'expense', 'Assicurazione e bollo', $start->modify('+18 days'), 189, 'Bollo Panda', [], $panda);
        $this->add($savings, 'expense', 'Assicurazione e bollo', $start->modify('+2 months +4 days'), 612, 'Assicurazione Panda', [], $panda);
        $this->add($savings, 'expense', 'Manutenzione auto', $start->modify('+4 months +8 days'), 348, 'Tagliando Panda', [], $panda);
        $this->add($main, 'income', 'Rimborsi', $today->modify('-1 day'), 45, 'Rimborso cena Luca');
        $this->add($main, 'expense', 'Regali', $today->modify('-9 days'), 60, 'Regalo Giulia', ['festa-giulia']);
        $this->add($main, 'expense', 'Spesa', $today->modify('-10 days'), 38.20, 'Esselunga', ['festa-giulia']);

        // Viaggio a Lisbona: spese di categorie diverse sotto la stessa etichetta.
        $trip = $today->modify('-12 days');
        $this->add($main, 'expense', 'Viaggi', $trip->modify('-32 days'), 186, 'Volo TAP A/R', ['lisbona-26']);
        $this->add($main, 'expense', 'Viaggi', $trip->modify('-26 days'), 264, 'Airbnb Alfama, 3 notti', ['lisbona-26']);
        $this->add($cash, 'expense', 'Mezzi pubblici', $trip, 18.50, 'Taxi aeroporto', ['lisbona-26']);
        $this->add($cash, 'expense', 'Ristoranti e bar', $trip, 12.40, 'Pastéis de Belém', ['lisbona-26']);
        $this->add($main, 'expense', 'Ristoranti e bar', $trip->modify('+1 day'), 46.80, 'Time Out Market', ['lisbona-26']);
        $this->add($main, 'expense', 'Svago', $trip->modify('+2 days'), 10, 'Museo Gulbenkian', ['lisbona-26']);
        $this->add($cash, 'expense', 'Mezzi pubblici', $trip->modify('+3 days'), 12, 'Viva Viagem, metro', ['lisbona-26']);
        $this->tags['lisbona-26']->setTitle('Viaggio a Lisbona')->setColor('#C3A6FF');
        $this->tags['festa-giulia']->setColor('#FF9EC7');

        // Rifornimenti: Panda ogni ~12 giorni, SH ogni ~3 settimane.
        $this->refuels($main, $panda, $start->modify('+3 days'), 41500, 12, [560, 660], [15.8, 18.6], [1.739, 1.849], 0.0);
        $this->refuels($cash, $sh, $start->modify('+6 days'), 11200, 21, [210, 270], [32.0, 37.0], [1.759, 1.859], 0.0);

        // Investimenti: ETF con versamento mensile dal conto principale e Bitcoin piu' ballerino.
        $etf = (new Holding($this->user))->setName('S&P 500')->setSymbol('VUAA')->setKind('etf')->setColor('#7FD6FF')->setNote('Piano di accumulo mensile');
        $btc = (new Holding($this->user))->setName('Bitcoin')->setSymbol('BTC')->setKind('crypto')->setColor('#FFB86B');
        $this->em->persist($etf);
        $this->em->persist($btc);
        $etfValue = 0.0;
        $btcValue = 0.0;
        $i = 0;
        for ($month = $start->modify('-4 months'); $month <= $today; $month = $month->modify('+1 month'), ++$i) {
            $day = $month->setDate((int) $month->format('Y'), (int) $month->format('m'), 2);
            if ($day > $today) {
                break;
            }
            $put = $i === 0 ? 1000 : 200;
            $etfValue = $etfValue * (1 + mt_rand(-250, 400) / 10000) + $put;
            $this->entry($etf, $day, $put, $etfValue, $main);
            $btcPut = $i === 0 ? 500 : ($i % 2 ? 100 : 0);
            $btcValue = $btcValue * (1 + mt_rand(-1200, 1500) / 10000) + $btcPut;
            $this->entry($btc, $day, $btcPut, $btcValue, $btcPut > 0 ? $main : null);
        }

        $this->em->flush();
        $io->success(sprintf('Utente demo "%s" (password "%s") creato con dati da %s a oggi.', $username, $password, $start->format('d/m/Y')));

        return Command::SUCCESS;
    }

    private function add(Account $account, string $kind, string $category, \DateTimeImmutable $date, float $amount, string $description, array $tags = [], ?Vehicle $vehicle = null): ?Movement
    {
        if ($date > new \DateTimeImmutable('today')) {
            return null;
        }
        $m = (new Movement($this->user, $account))->setKind($kind)->setCategory($this->cat[$category])
            ->setDate($date)->setAmount(number_format($amount, 2, '.', ''))->setDescription($description)->setVehicle($vehicle);
        foreach ($tags as $name) {
            $this->tags[$name] ??= new Tag($this->user, $name);
            $this->em->persist($this->tags[$name]);
            $m->addTag($this->tags[$name]);
        }
        $this->em->persist($m);

        return $m;
    }

    private function transfer(Account $from, Account $to, \DateTimeImmutable $date, float $amount, string $description): void
    {
        if ($date > new \DateTimeImmutable('today')) {
            return;
        }
        $this->em->persist((new Movement($this->user, $from))->setKind(Movement::TRANSFER)->setToAccount($to)
            ->setDate($date)->setAmount(number_format($amount, 2, '.', ''))->setDescription($description));
    }

    private function refuels(Account $account, Vehicle $vehicle, \DateTimeImmutable $date, int $odometer, int $every, array $km, array $eff, array $price, float $_): void
    {
        $today = new \DateTimeImmutable('today');
        $n = 0;
        while ($date <= $today) {
            $odometer += mt_rand($km[0], $km[1]);
            $full = ++$n % 7 !== 0;
            $liters = round(($km[0] + $km[1]) / 2 / (mt_rand((int) ($eff[0] * 10), (int) ($eff[1] * 10)) / 10), 2);
            $ppl = mt_rand((int) ($price[0] * 1000), (int) ($price[1] * 1000)) / 1000;
            $stations = ['Q8', 'Eni', 'IP', 'Tamoil'];
            $m = $this->add($account, 'expense', 'Carburante', $date, round($liters * $ppl, 2), 'Benzina '.$stations[mt_rand(0, 3)], [], $vehicle);
            (new Refuel($m))->setOdometer($odometer)->setLiters(number_format($liters, 2, '.', ''))
                ->setPricePerLiter(number_format($ppl, 3, '.', ''))->setFullTank($full);
            $date = $date->modify('+'.($every + mt_rand(-3, 3)).' days');
        }
    }

    private function entry(Holding $holding, \DateTimeImmutable $date, float $put, float $value, ?Account $from): void
    {
        $entry = (new InvestmentEntry($holding))->setDate($date)
            ->setContribution(number_format($put, 2, '.', ''))->setValue(number_format($value, 2, '.', ''));
        if ($from && $put > 0) {
            $m = (new Movement($this->user, $from))->setKind(Movement::TRANSFER)->setDate($date)
                ->setAmount(number_format($put, 2, '.', ''))->setDescription('Versamento '.$holding->getName());
            $this->em->persist($m);
            $entry->setMovement($m);
        }
        $this->em->persist($entry);
    }
}
