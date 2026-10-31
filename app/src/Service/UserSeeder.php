<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Cio' che ogni nuovo utente trova gia' pronto: un conto e un set di categorie da personalizzare, coi nomi
 * nella lingua di chi li crea (da console l'italiano: app:demo cerca le categorie per nome italiano).
 */
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

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function seed(User $user): void
    {
        $account = (new Account($user))->setName($this->translator->trans('Conto principale'))->setKind('checking')->setColor('#8AB4FF')->setIsDefault(true);
        $this->em->persist($account);
        $this->seedCategories($account);
    }

    /** Le categorie predefinite, per un conto nuovo che non le copia da un altro. */
    public function seedCategories(Account $account): void
    {
        foreach (self::EXPENSE as $pos => [$name, $icon, $color, $children]) {
            $parent = (new Category($account))->setName($this->translator->trans($name))->setIcon($icon)->setColor($color)->setKind(Category::EXPENSE)->setPosition($pos);
            $this->em->persist($parent);
            foreach ($children as $childPos => $child) {
                $sub = (new Category($account))->setParent($parent)->setName($this->translator->trans($child[0]))->setIcon($child[1])
                    ->setColor($color)->setKind(Category::EXPENSE)->setFuel($child[2] ?? false)->setPosition($childPos);
                // Anche nella collezione del padre: chi copia le categorie subito dopo (app:demo) le deve vedere
                $parent->getChildren()->add($sub);
                $this->em->persist($sub);
            }
        }
        foreach (self::INCOME as $pos => [$name, $icon, $color]) {
            $this->em->persist((new Category($account))->setName($this->translator->trans($name))->setIcon($icon)->setColor($color)->setKind(Category::INCOME)->setPosition($pos));
        }
    }
}
