<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** Cio' che ogni nuovo utente trova gia' pronto: un conto e un set di categorie da personalizzare. */
final class UserSeeder
{
    private const EXPENSE = [
        ['Casa', 'house', '#8AB4FF', [['Affitto o mutuo', 'key-round'], ['Bollette', 'zap'], ['Manutenzione casa', 'wrench']]],
        ['Spesa', 'shopping-cart', '#FFB86B', []],
        ['Ristoranti e bar', 'utensils', '#FF8A80', []],
        ['Trasporti', 'car', '#6FDCCB', [['Carburante', 'fuel', true], ['Mezzi pubblici', 'train-front'], ['Pedaggi e parcheggi', 'circle-parking'], ['Assicurazione e bollo', 'shield'], ['Manutenzione auto', 'wrench']]],
        ['Salute', 'heart-pulse', '#FF9EC7', []],
        ['Svago', 'ticket', '#F3E06A', []],
        ['Viaggi', 'plane', '#C3A6FF', []],
        ['Abbigliamento', 'shirt', '#D9B48F', []],
        ['Abbonamenti', 'repeat', '#7FD6FF', []],
        ['Regali', 'gift', '#FF9EC7', []],
        ['Altre spese', 'circle-ellipsis', '#A3A59F', []],
    ];

    private const INCOME = [
        ['Stipendio', 'briefcase', '#9BE7A0'],
        ['Rimborsi', 'undo-2', '#6FDCCB'],
        ['Regali ricevuti', 'gift', '#F3E06A'],
        ['Altre entrate', 'hand-coins', '#A3A59F'],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function seed(User $user): void
    {
        $account = (new Account($user))->setName('Conto principale')->setKind('checking')->setColor('#8AB4FF')->setIsDefault(true);
        $this->em->persist($account);

        foreach (self::EXPENSE as $pos => [$name, $icon, $color, $children]) {
            $parent = (new Category($user))->setName($name)->setIcon($icon)->setColor($color)->setKind(Category::EXPENSE)->setPosition($pos);
            $this->em->persist($parent);
            foreach ($children as $childPos => $child) {
                $this->em->persist((new Category($user))->setParent($parent)->setName($child[0])->setIcon($child[1])
                    ->setColor($color)->setKind(Category::EXPENSE)->setFuel($child[2] ?? false)->setPosition($childPos));
            }
        }
        foreach (self::INCOME as $pos => [$name, $icon, $color]) {
            $this->em->persist((new Category($user))->setName($name)->setIcon($icon)->setColor($color)->setKind(Category::INCOME)->setPosition($pos));
        }
    }
}
